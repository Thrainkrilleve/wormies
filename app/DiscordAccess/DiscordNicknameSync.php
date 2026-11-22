<?php

declare(strict_types=1);

namespace App\DiscordAccess;

use App\Actions\EnsureOrganisationExistsAction;
use App\Models\Alliance;
use App\Models\Character;
use App\Models\Corporation;
use App\Models\DiscordAccount;
use App\Models\MapDiscordAccessAudit;
use App\Models\MapDiscordServer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sets a linked member's Discord nickname from their main character.
 *
 * The main character is preferred_character_id when that character still
 * belongs to the user. Otherwise it is the user's first character. The link
 * page uses the same fallback when the session has no active character.
 */
final class DiscordNicknameSync
{
    public function __construct(
        private readonly DiscordAccessBot $bot,
        private readonly EnsureOrganisationExistsAction $organisations,
    ) {}

    public function sync(string $discordUserId): void
    {
        $this->apply($discordUserId, clear: false);
    }

    public function clear(string $discordUserId): void
    {
        $this->apply($discordUserId, clear: true);
    }

    public function refreshAll(): void
    {
        if (! $this->bot->configured()) {
            return;
        }

        DiscordAccount::query()
            ->orderBy('id')
            ->pluck('discord_user_id')
            ->each(function (string $discordUserId): void {
                try {
                    $this->sync($discordUserId);
                } catch (Throwable $exception) {
                    $this->logOnce('failed', '', $discordUserId, $exception->getMessage());
                }
            });
    }

    private function apply(string $discordUserId, bool $clear): void
    {
        if (! $this->bot->configured()) {
            return;
        }

        $account = DiscordAccount::query()->where('discord_user_id', $discordUserId)->first();
        if (! $account instanceof DiscordAccount) {
            return;
        }

        $servers = MapDiscordServer::query()
            ->where('status', MapDiscordServer::Active)
            ->where('nickname_sync', true)
            ->whereNotNull('guild_id')
            ->orderBy('id')
            ->get();
        if ($servers->isEmpty()) {
            return;
        }

        $nick = $clear ? null : $this->nicknameFor($account);
        if (! $clear && ($nick === null || $nick === '')) {
            return;
        }

        $servers->each(function (MapDiscordServer $server) use ($account, $discordUserId, $nick, $clear): void {
            $guildId = (string) $server->guild_id;
            try {
                $result = $this->bot->changeNickname($guildId, $discordUserId, $nick);
            } catch (Throwable $exception) {
                $this->logOnce('failed', $guildId, $discordUserId, $exception->getMessage());

                return;
            }

            if ($result === 'updated') {
                $this->audit($server, $account, $clear ? 'reset' : 'set');

                return;
            }

            if (in_array($result, ['denied', 'failed'], true)) {
                $this->logOnce($result, $guildId, $discordUserId, 'Discord did not change the nickname.');
            }
        });
    }

    private function nicknameFor(DiscordAccount $account): ?string
    {
        $user = $account->user;
        if (! $user instanceof User) {
            return null;
        }

        $character = $this->mainCharacter($user);
        if (! $character instanceof Character || mb_trim($character->name) === '') {
            return null;
        }

        return DiscordAccessNickname::format(
            $this->ticker($character->alliance, $character->alliance_id, false),
            $this->ticker($character->corporation, $character->corporation_id, true),
            $character->name,
        );
    }

    /**
     * Preferred character, then the first character. This matches the link page
     * when the browser has not chosen a different active character.
     */
    private function mainCharacter(User $user): ?Character
    {
        $user->load(['characters.corporation', 'characters.alliance']);
        $preferred = $user->getAttributes()['preferred_character_id'] ?? null;
        if (is_numeric($preferred)) {
            $character = $user->characters->firstWhere('id', (int) $preferred);
            if ($character instanceof Character) {
                return $character;
            }
        }

        $first = $user->characters->sortBy('id')->first();

        return $first instanceof Character ? $first : null;
    }

    private function ticker(Corporation|Alliance|null $organisation, ?int $id, bool $corporation): ?string
    {
        if (! $organisation instanceof Corporation && ! $organisation instanceof Alliance) {
            return null;
        }

        $storedTicker = $organisation->getAttributes()['ticker'] ?? null;
        $ticker = is_string($storedTicker) ? mb_trim($storedTicker) : '';
        if ($ticker !== '') {
            return $ticker;
        }

        $unresolvable = $organisation->getAttributes()['unresolvable_at'] ?? null;
        if ($id === null || $id === 0 || $unresolvable !== null) {
            return null;
        }

        try {
            if ($corporation) {
                $this->organisations->refreshCorporation($id);
            } else {
                $this->organisations->refreshAlliance($id);
            }
        } catch (Throwable $exception) {
            $this->logOnce('esi', (string) $id, '', $exception->getMessage());

            return null;
        }

        $fresh = $organisation->fresh();
        $storedTicker = $fresh?->getAttributes()['ticker'] ?? null;
        $ticker = is_string($storedTicker) ? mb_trim($storedTicker) : '';

        return $ticker === '' ? null : $ticker;
    }

    private function audit(MapDiscordServer $server, DiscordAccount $account, string $reason): void
    {
        MapDiscordAccessAudit::query()->create([
            'map_id' => $server->map_id,
            'guild_id' => (string) $server->guild_id,
            'user_id' => $account->user_id,
            'discord_user_id' => $account->discord_user_id,
            'action' => MapDiscordAccessAudit::Nickname,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function logOnce(string $kind, string $guildId, string $discordUserId, string $message): void
    {
        $key = 'discord-access.nickname.'.$kind.'.'.$guildId.'.'.$discordUserId;
        if (! Cache::add($key, true, now()->addDay())) {
            return;
        }

        Log::warning('Discord nickname was not changed.', [
            'guild_id' => $guildId,
            'discord_user_id' => $discordUserId,
            'reason' => $message,
        ]);
    }
}
