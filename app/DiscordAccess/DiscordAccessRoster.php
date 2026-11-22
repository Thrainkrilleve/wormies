<?php

declare(strict_types=1);

namespace App\DiscordAccess;

use App\Models\Alliance;
use App\Models\Corporation;
use App\Models\Map;
use App\Models\MapDiscordGrant;
use App\Models\MapDiscordServer;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Linked Discord members for a map manager. Discord user ids are never included.
 */
final class DiscordAccessRoster
{
    public const int PerPage = 25;

    /**
     * @return array{
     *     members: list<array{username: string, user_name: string, characters: list<array{name: string, corporation: array{name: string, ticker: string, as_of: string|null}|null, alliance: array{name: string, ticker: string, as_of: string|null}|null}>}>,
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     last_page: int,
     *     unmatched_count: int,
     *     corporations: list<array{id: int, name: string, ticker: string, count: int}>,
     *     alliances: list<array{id: int, name: string, ticker: string, count: int}>,
     *     corporation_id: int|null,
     *     alliance_id: int|null
     * }
     */
    public function for(Map $map, MapDiscordServer $server, Request $request): array
    {
        $corporationId = $this->filterId($request->query('corporation_id'));
        $allianceId = $this->filterId($request->query('alliance_id'));
        $page = max(1, $request->integer('roster_page', 1));

        $grants = $this->grants($map, $server);
        $corporations = [];
        $alliances = [];
        $matching = [];

        foreach ($grants as $grant) {
            $user = $grant->user;
            $this->countAffiliations($user, $corporations, $alliances);
            if ($this->matches($user, $corporationId, $allianceId)) {
                $matching[] = $grant;
            }
        }

        $total = count($matching);
        $lastPage = max(1, (int) ceil($total / self::PerPage));
        $page = min($page, $lastPage);
        $slice = array_slice($matching, ($page - 1) * self::PerPage, self::PerPage);

        return [
            'members' => array_map($this->member(...), $slice),
            'page' => $page,
            'per_page' => self::PerPage,
            'total' => $total,
            'last_page' => $lastPage,
            'unmatched_count' => $server->unmatched_count,
            'corporations' => array_values($corporations),
            'alliances' => array_values($alliances),
            'corporation_id' => $corporationId,
            'alliance_id' => $allianceId,
        ];
    }

    /** @return Collection<int, MapDiscordGrant> */
    private function grants(Map $map, MapDiscordServer $server): Collection
    {
        if ($server->guild_id === null) {
            return new Collection;
        }

        return MapDiscordGrant::query()
            ->where('map_id', $map->id)
            ->where('guild_id', $server->guild_id)
            ->with(['user.discordAccount', 'user.characters.corporation', 'user.characters.alliance'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<int, array{id: int, name: string, ticker: string, count: int}>  $corporations
     * @param  array<int, array{id: int, name: string, ticker: string, count: int}>  $alliances
     */
    private function countAffiliations(User $user, array &$corporations, array &$alliances): void
    {
        $seenCorporations = [];
        $seenAlliances = [];

        foreach ($user->characters as $character) {
            $corporation = $character->corporation;
            if ($corporation instanceof Corporation && ! isset($seenCorporations[$corporation->id])) {
                $seenCorporations[$corporation->id] = true;
                $corporations[$corporation->id] ??= [
                    'id' => $corporation->id,
                    'name' => $corporation->name,
                    'ticker' => (string) $corporation->ticker,
                    'count' => 0,
                ];
                $corporations[$corporation->id]['count']++;
            }

            $alliance = $character->alliance;
            if ($alliance instanceof Alliance && ! isset($seenAlliances[$alliance->id])) {
                $seenAlliances[$alliance->id] = true;
                $alliances[$alliance->id] ??= [
                    'id' => $alliance->id,
                    'name' => $alliance->name,
                    'ticker' => (string) $alliance->ticker,
                    'count' => 0,
                ];
                $alliances[$alliance->id]['count']++;
            }
        }
    }

    private function matches(User $user, ?int $corporationId, ?int $allianceId): bool
    {
        if ($corporationId === null && $allianceId === null) {
            return true;
        }

        foreach ($user->characters as $character) {
            $corporationMatches = $corporationId === null || $character->corporation_id === $corporationId;
            $allianceMatches = $allianceId === null || $character->alliance_id === $allianceId;
            if ($corporationMatches && $allianceMatches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{username: string, user_name: string, characters: list<array{name: string, corporation: array{name: string, ticker: string, as_of: string|null}|null, alliance: array{name: string, ticker: string, as_of: string|null}|null}>}
     */
    private function member(MapDiscordGrant $grant): array
    {
        $user = $grant->user;
        $username = $user->discordAccount?->username;
        $characters = [];
        foreach ($user->characters as $character) {
            $characters[] = [
                'name' => $character->name,
                'corporation' => $this->organisation($character->corporation),
                'alliance' => $this->organisation($character->alliance),
            ];
        }

        return [
            'username' => is_string($username) && $username !== '' ? $username : 'Linked account',
            'user_name' => $user->name,
            'characters' => $characters,
        ];
    }

    /**
     * @return array{name: string, ticker: string, as_of: string|null}|null
     */
    private function organisation(Corporation|Alliance|null $organisation): ?array
    {
        if ($organisation === null) {
            return null;
        }

        $asOf = $organisation->getAttributes()['last_updated'] ?? null;

        return [
            'name' => $organisation->name,
            'ticker' => $organisation->ticker ?? '',
            'as_of' => is_string($asOf) && $asOf !== '' ? $asOf : null,
        ];
    }

    private function filterId(mixed $value): ?int
    {
        if (! is_scalar($value) || ! preg_match('/^\d+$/', (string) $value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
