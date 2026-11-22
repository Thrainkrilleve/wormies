<?php

declare(strict_types=1);

namespace App\DiscordAccess;

use App\Models\DiscordAccount;
use App\Models\MapDiscordAccessAudit;
use App\Models\MapDiscordGrant;
use App\Models\MapDiscordServer;
use Illuminate\Support\Facades\DB;

final class DiscordAccessSync
{
    public function __construct(private readonly DiscordAccessBot $bot) {}

    public function startInvite(MapDiscordServer $server): MapDiscordServer
    {
        $server->status = MapDiscordServer::Pending;
        $server->pending_expires_at = now()->addMinutes(15);
        $server->save();

        return $server;
    }

    /**
     * Bind a guild the bot just joined. Only one unexpired invite may be waiting,
     * and a guild the bot is already connected to for another map is left alone.
     */
    public function bindNewGuild(string $guildId): bool
    {
        if (MapDiscordServer::query()->where('guild_id', $guildId)->where('status', MapDiscordServer::Active)->exists()) {
            return false;
        }

        $pending = MapDiscordServer::query()
            ->where('status', MapDiscordServer::Pending)
            ->where('pending_expires_at', '>', now())
            ->get();

        if ($pending->count() !== 1) {
            return false;
        }

        /** @var MapDiscordServer $server */
        $server = $pending->first();
        $server->guild_id = $guildId;
        $server->guild_name = $this->bot->configured() ? $this->bot->guildName($guildId) : null;
        $server->status = MapDiscordServer::Active;
        $server->pending_expires_at = null;
        $server->save();

        if ($this->bot->configured()) {
            $this->resync($server);
        }

        return true;
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

    private function activeServer(string $guildId): ?MapDiscordServer
    {
        return MapDiscordServer::query()
            ->where('guild_id', $guildId)
            ->where('status', MapDiscordServer::Active)
            ->first();
    }
}
