<?php

declare(strict_types=1);

namespace App\DiscordAccess;

use App\Enums\Permission;
use App\Models\DiscordAccount;
use App\Models\MapDiscordAccessAudit;
use App\Models\MapDiscordGrant;
use App\Models\MapDiscordServer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DiscordAccessSync
{
    public function __construct(
        private readonly DiscordAccessBot $bot,
        private readonly CreateDiscordAccessLink $links,
    ) {}

    public function startInvite(MapDiscordServer $server, string $state): MapDiscordServer
    {
        $server->status = MapDiscordServer::Pending;
        $server->permission = Permission::Member->value;
        $server->guild_id = null;
        $server->guild_name = null;
        $server->unmatched_count = 0;
        $server->pending_expires_at = now()->addMinutes(15);
        $server->pending_state_hash = DiscordAccessState::hash($state);
        $server->save();

        return $server;
    }

    /**
     * Bind a guild the bot just joined. Only one unexpired invite may be waiting,
     * and a guild the bot is already connected to for another map is left alone.
     * Guilds the bot already knew are not passed here.
     */
    public function bindNewGuild(string $guildId): bool
    {
        if (preg_match('/^\d{1,20}$/', $guildId) !== 1) {
            return false;
        }

        $bound = DB::transaction(function () use ($guildId): ?MapDiscordServer {
            if (MapDiscordServer::query()->where('guild_id', $guildId)->where('status', MapDiscordServer::Active)->lockForUpdate()->exists()) {
                return null;
            }

            $pending = MapDiscordServer::query()
                ->where('status', MapDiscordServer::Pending)
                ->where('pending_expires_at', '>', now())
                ->lockForUpdate()
                ->get();

            if ($pending->count() !== 1) {
                return null;
            }

            /** @var MapDiscordServer $server */
            $server = $pending->first();
            $this->activate($server, $guildId, null);

            return $server;
        });

        if (! $bound instanceof MapDiscordServer) {
            return false;
        }

        if ($this->bot->configured()) {
            $bound->guild_name = $this->bot->guildName($guildId);
            $bound->save();
            $this->resync($bound);
        }

        return true;
    }

    /**
     * Bind the invite identified by the OAuth state. Returns an error message, or null when the guild is bound.
     */
    public function bindAuthorizedGuild(MapDiscordServer $server, string $guildId): ?string
    {
        if (preg_match('/^\d{1,20}$/', $guildId) !== 1) {
            return 'Discord did not confirm a server for that authorization.';
        }

        if (! $this->bot->configured()) {
            return 'The access bot is not configured.';
        }

        $guildName = $this->bot->guildName($guildId);
        if ($guildName === null) {
            return 'The access bot is not in that Discord server.';
        }

        $error = DB::transaction(function () use ($server, $guildId, $guildName): ?string {
            $locked = MapDiscordServer::query()->lockForUpdate()->find($server->id);
            if (! $locked instanceof MapDiscordServer) {
                return 'This invite has expired. Start a new one.';
            }

            if ($locked->status === MapDiscordServer::Active) {
                if ($locked->guild_id !== $guildId) {
                    return 'This map is already connected to a Discord server.';
                }

                $locked->pending_state_hash = null;
                $locked->pending_expires_at = null;
                $locked->save();

                return null;
            }

            if (! $locked->isPending()) {
                return 'This invite has expired. Start a new one.';
            }

            $taken = MapDiscordServer::query()
                ->where('guild_id', $guildId)
                ->where('status', MapDiscordServer::Active)
                ->whereKeyNot($locked->id)
                ->lockForUpdate()
                ->exists();
            if ($taken) {
                return 'That Discord server is already connected to another map.';
            }

            try {
                $this->activate($locked, $guildId, $guildName);
                $locked->pending_state_hash = null;
                $locked->save();
            } catch (QueryException $exception) {
                $errorInfo = $exception->errorInfo;
                if (! is_array($errorInfo) || ($errorInfo[0] ?? null) !== '23000') {
                    throw $exception;
                }

                return 'That Discord server is already connected to another map.';
            }

            return null;
        });

        if ($error !== null) {
            return $error;
        }

        $fresh = $server->fresh();
        if ($fresh instanceof MapDiscordServer) {
            $this->resync($fresh);
        }

        return null;
    }

    public function onMemberJoin(string $guildId, string $discordUserId): void
    {
        $server = $this->activeServer($guildId);
        if (! $server instanceof MapDiscordServer) {
            return;
        }

        $this->grant($server, $discordUserId, DiscordAccessReason::Join);
    }

    public function onMemberLeave(string $guildId, string $discordUserId): void
    {
        $this->revoke($guildId, $discordUserId, DiscordAccessReason::Leave);
    }

    public function changeDerivedPermission(MapDiscordServer $server, User $actor, Permission $next): void
    {
        if (! in_array($next, [Permission::Viewer, Permission::Member], true) || $server->guild_id === null) {
            return;
        }

        $current = $server->derivedPermission();
        if ($current === $next) {
            return;
        }

        $from = $current instanceof Permission ? $current->value : 'none';
        $server->permission = $next->value;
        $server->save();
        $this->audit($server->map_id, $server->guild_id, $actor->id, '0', MapDiscordAccessAudit::Permission, $from.'->'.$next->value);
    }

    public function unlinkInvokedUser(string $discordUserId): string
    {
        $this->links->forgetFor($discordUserId);
        $account = DiscordAccount::query()->where('discord_user_id', $discordUserId)->first();
        if (! $account instanceof DiscordAccount) {
            return 'You are not linked.';
        }

        $account->delete();

        return 'This Discord account is no longer linked. Derived map access was removed.';
    }

    public function revokeDiscordUser(string $discordUserId, string $reason): void
    {
        MapDiscordGrant::query()
            ->where('discord_user_id', $discordUserId)
            ->get()
            ->each(function (MapDiscordGrant $grant) use ($reason): void {
                $this->deleteGrant($grant, $reason);
            });
    }

    public function revokeGuild(string $guildId, string $reason): void
    {
        MapDiscordGrant::query()
            ->where('guild_id', $guildId)
            ->get()
            ->each(function (MapDiscordGrant $grant) use ($reason): void {
                $this->deleteGrant($grant, $reason);
            });

        MapDiscordServer::query()
            ->where('guild_id', $guildId)
            ->update([
                'guild_id' => null,
                'status' => MapDiscordServer::Removed,
                'unmatched_count' => 0,
            ]);
    }

    public function confirmMembership(string $discordUserId): void
    {
        if (! $this->bot->configured()) {
            return;
        }

        MapDiscordServer::query()
            ->where('status', MapDiscordServer::Active)
            ->whereNotNull('guild_id')
            ->get()
            ->each(function (MapDiscordServer $server) use ($discordUserId): void {
                $guildId = (string) $server->guild_id;
                $membership = $this->bot->membership($guildId, $discordUserId);
                if ($membership === 'removed') {
                    $this->revokeGuild($guildId, DiscordAccessReason::BotRemoved);

                    return;
                }
                if ($membership === 'present') {
                    $this->grant($server, $discordUserId, DiscordAccessReason::Link);
                }
            });
    }

    public function resyncAll(): void
    {
        MapDiscordServer::query()
            ->where('status', MapDiscordServer::Active)
            ->whereNotNull('guild_id')
            ->get()
            ->each(fn (MapDiscordServer $server) => $this->resync($server));
    }

    public function resync(MapDiscordServer $server): void
    {
        if ($server->status !== MapDiscordServer::Active || $server->guild_id === null) {
            return;
        }

        try {
            $memberIds = $this->bot->listMemberIds($server->guild_id);
        } catch (DiscordAccessBotRemoved) {
            $this->revokeGuild($server->guild_id, DiscordAccessReason::BotRemoved);

            return;
        }

        $linked = DiscordAccount::query()
            ->whereIn('discord_user_id', $memberIds === [] ? [''] : $memberIds)
            ->pluck('user_id', 'discord_user_id');

        $present = [];
        foreach ($memberIds as $discordUserId) {
            if (! $linked->has($discordUserId)) {
                continue;
            }
            $present[] = $discordUserId;
            $this->grant($server, $discordUserId, DiscordAccessReason::Resync);
        }

        MapDiscordGrant::query()
            ->where('map_id', $server->map_id)
            ->where('guild_id', $server->guild_id)
            ->when($present !== [], fn ($query) => $query->whereNotIn('discord_user_id', $present))
            ->get()
            ->each(fn (MapDiscordGrant $grant) => $this->deleteGrant($grant, DiscordAccessReason::Resync));

        $server->unmatched_count = count($memberIds) - count($present);
        $server->save();
    }

    private function grant(MapDiscordServer $server, string $discordUserId, string $reason): void
    {
        $account = DiscordAccount::query()->where('discord_user_id', $discordUserId)->first();
        if (! $account instanceof DiscordAccount || $server->guild_id === null) {
            return;
        }

        $existing = MapDiscordGrant::query()
            ->where('map_id', $server->map_id)
            ->where('discord_user_id', $discordUserId)
            ->first();
        if ($existing instanceof MapDiscordGrant) {
            return;
        }

        $grant = MapDiscordGrant::query()->create([
            'map_id' => $server->map_id,
            'guild_id' => $server->guild_id,
            'user_id' => $account->user_id,
            'discord_user_id' => $discordUserId,
        ]);

        $this->audit($grant->map_id, $grant->guild_id, $grant->user_id, $discordUserId, MapDiscordAccessAudit::Grant, $reason);
    }

    private function revoke(string $guildId, string $discordUserId, string $reason): void
    {
        MapDiscordGrant::query()
            ->where('guild_id', $guildId)
            ->where('discord_user_id', $discordUserId)
            ->get()
            ->each(fn (MapDiscordGrant $grant) => $this->deleteGrant($grant, $reason));
    }

    private function deleteGrant(MapDiscordGrant $grant, string $reason): void
    {
        DB::transaction(function () use ($grant, $reason): void {
            $this->audit($grant->map_id, $grant->guild_id, $grant->user_id, $grant->discord_user_id, MapDiscordAccessAudit::Revoke, $reason);
            $grant->delete();
        });
    }

    private function audit(?int $mapId, string $guildId, ?int $userId, string $discordUserId, string $action, string $reason): void
    {
        MapDiscordAccessAudit::query()->create([
            'map_id' => $mapId,
            'guild_id' => $guildId,
            'user_id' => $userId,
            'discord_user_id' => $discordUserId,
            'action' => $action,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function activate(MapDiscordServer $server, string $guildId, ?string $guildName): void
    {
        $server->guild_id = $guildId;
        $server->guild_name = $guildName;
        $server->status = MapDiscordServer::Active;
        $server->pending_expires_at = null;
        $server->save();
    }

    private function activeServer(string $guildId): ?MapDiscordServer
    {
        return MapDiscordServer::query()
            ->where('guild_id', $guildId)
            ->where('status', MapDiscordServer::Active)
            ->first();
    }
}
