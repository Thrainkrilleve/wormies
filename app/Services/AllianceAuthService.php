<?php

declare(strict_types=1);

namespace App\Services;

use App\Exception\CharacterNotAuthorizedException;
use App\Jobs\UpdateAffiliations;
use App\Models\Alliance;
use App\Models\Character;
use App\Models\Corporation;
use App\Models\EsiScope;
use App\Models\User;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use NicolasKion\Esi\DTO\CharacterAffiliation;
use NicolasKion\Esi\Esi;

final readonly class AllianceAuthService
{
    public function __construct(
        private Esi $esi,
        private AffiliationWhitelist $whitelist,
    ) {}

    public function getBaseUrl(): string
    {
        return rtrim((string) config('services.allianceauth.base_url', 'https://auth.r3v-w.space'), '/');
    }

    public function getInternalUrl(): string
    {
        $internal = (string) config('services.allianceauth.internal_url');

        return $internal !== '' ? rtrim($internal, '/') : $this->getBaseUrl();
    }

    public function getClientId(): string
    {
        return (string) config('services.allianceauth.client_id');
    }

    public function getClientSecret(): string
    {
        return (string) config('services.allianceauth.client_secret');
    }

    public function getRedirectUri(): string
    {
        return (string) config('services.allianceauth.redirect', route('allianceauth.callback'));
    }

    public function getAuthorizationUrl(string $state, ?string $extraScopes = null): string
    {
        $baseScopes = (string) config('services.allianceauth.scopes', 'openid profile email groups');
        $scope = $extraScopes ? trim($baseScopes . ' ' . $extraScopes) : $baseScopes;

        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->getClientId(),
            'redirect_uri' => $this->getRedirectUri(),
            'scope' => $scope,
            'state' => $state,
        ]);

        return sprintf('%s/o/authorize/?%s', $this->getBaseUrl(), $params);
    }

    /**
     * @return array{0: User, 1: Character}
     *
     * @throws ConnectionException
     * @throws CharacterNotAuthorizedException
     * @throws Exception
     */
    public function handleCallback(string $code, ?int $addToAccountId = null): array
    {
        $headers = [];
        $host = parse_url($this->getBaseUrl(), PHP_URL_HOST);
        if ($host) {
            $headers['Host'] = $host;
        }

        // 1. Exchange authorization code for access token
        $tokenResponse = Http::withHeaders($headers)->asForm()->post($this->getInternalUrl().'/o/token/', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
            'redirect_uri' => $this->getRedirectUri(),
            'code' => $code,
        ]);

        if (! $tokenResponse->successful()) {
            throw new Exception('Failed to obtain token from Alliance Auth: '.$tokenResponse->body());
        }

        $tokenData = $tokenResponse->json();
        $accessToken = $tokenData['access_token'] ?? null;

        if (! $accessToken) {
            throw new Exception('No access_token returned by Alliance Auth.');
        }

        // 2. Fetch userinfo from Alliance Auth OIDC provider
        $userInfoResponse = Http::withHeaders($headers)->withToken((string) $accessToken)
            ->get($this->getInternalUrl().'/o/userinfo/');

        if (! $userInfoResponse->successful()) {
            throw new Exception('Failed to fetch userinfo from Alliance Auth: '.$userInfoResponse->body());
        }

        /** @var array<string, mixed> $userInfo */
        $userInfo = $userInfoResponse->json();

        // 3. Verify groups / state permissions if required
        $this->verifyGroupPermissions($userInfo);

        // 4. Resolve character ID & Name
        [$characterId, $characterName] = $this->resolveCharacterIdentity($userInfo);

        if (! $characterId || ! $characterName) {
            throw new Exception('Could not determine EVE Character identity from Alliance Auth userinfo.');
        }

        // 5. Get character affiliations via ESI
        $affiliations = $this->esi->getAffiliations([$characterId]);

        if ($affiliations->failed() || $affiliations->data === []) {
            throw new Exception('Failed to retrieve character affiliations from EVE ESI.');
        }

        /** @var CharacterAffiliation $affiliation */
        $affiliation = $affiliations->data[0];
        UpdateAffiliations::dispatchSync($affiliation);

        // 6. Check general affiliation whitelist
        if (! $this->whitelist->allows([$characterId, $affiliation->corporation_id, $affiliation->alliance_id])) {
            throw new CharacterNotAuthorizedException();
        }

        // 7. Upsert Character record
        $this->ensureSocialsExists($affiliation);

        $character = Character::query()->updateOrCreate([
            'id' => $characterId,
        ], [
            'id' => $characterId,
            'name' => $characterName,
            'alliance_id' => $affiliation->alliance_id,
            'corporation_id' => $affiliation->corporation_id,
        ]);

        $character->characterStatus()->firstOrCreate();

        // 8. Associate to User account
        if ($addToAccountId !== null && $addToAccountId !== 0) {
            $user = User::query()->findOrFail($addToAccountId);
        } elseif ($character->user()->exists()) {
            $user = $character->user;
        } else {
            $user = User::query()->firstOrCreate([
                'name' => $characterName,
            ]);
        }

        $character->user()->associate($user);
        $character->save();

        // 9. Sync EVE ESI tokens provided by Alliance Auth for user's characters
        $this->syncUserEsiTokens($user, $character, $userInfo);

        // 10. Auto-verify Discord account if linked in Alliance Auth
        $this->syncDiscordAccount($user, $userInfo);

        return [$user, $character];
    }

    /**
     * @param  array<string, mixed>  $userInfo
     *
     * @throws CharacterNotAuthorizedException
     */
    private function verifyGroupPermissions(array $userInfo): void
    {
        $requiredGroupsRaw = (string) config('services.allianceauth.required_groups', '');
        if (trim($requiredGroupsRaw) === '') {
            return;
        }

        $requiredGroups = array_map('trim', explode(',', $requiredGroupsRaw));
        $userGroups = (array) ($userInfo['groups'] ?? []);

        // Also check state if returned
        if (isset($userInfo['state']) && is_string($userInfo['state'])) {
            $userGroups[] = $userInfo['state'];
        }

        $hasRequiredGroup = ! empty(array_intersect($requiredGroups, $userGroups));

        if (! $hasRequiredGroup) {
            throw new CharacterNotAuthorizedException('You do not belong to the required Alliance Auth group to access Wormhole Systems.');
        }
    }

    /**
     * @param  array<string, mixed>  $userInfo
     * @return array{0: ?int, 1: ?string}
     */
    private function resolveCharacterIdentity(array $userInfo): array
    {
        $characterId = isset($userInfo['character_id']) ? (int) $userInfo['character_id'] : null;
        $characterName = $userInfo['character_name'] ?? $userInfo['name'] ?? null;

        // If character_id and name are provided directly
        if ($characterId && $characterName) {
            return [$characterId, (string) $characterName];
        }

        // If we have character name but not ID, resolve via ESI universe/ids
        if ($characterName) {
            $idsResult = $this->esi->getIds([(string) $characterName]);
            if ($idsResult->wasSuccessful() && ! empty($idsResult->data->characters)) {
                $characterId = $idsResult->data->characters[0]->id;

                return [$characterId, (string) $characterName];
            }

            // Check existing character table
            $existing = Character::query()->where('name', $characterName)->first();
            if ($existing) {
                return [$existing->id, $existing->name];
            }
        }

        // If sub is a numeric character ID
        if (isset($userInfo['sub']) && is_numeric($userInfo['sub'])) {
            $subId = (int) $userInfo['sub'];
            if (! $characterName) {
                $existing = Character::query()->find($subId);
                if ($existing) {
                    return [$subId, $existing->name];
                }
            } else {
                return [$subId, (string) $characterName];
            }
        }

        return [$characterId, $characterName ? (string) $characterName : null];
    }

    private function ensureSocialsExists(CharacterAffiliation $affiliation): void
    {
        if ($affiliation->corporation_id !== 0) {
            Corporation::query()->updateOrCreate([
                'id' => $affiliation->corporation_id,
            ]);
        }

        if ($affiliation->alliance_id !== null && $affiliation->alliance_id !== 0) {
            Alliance::query()->updateOrCreate([
                'id' => $affiliation->alliance_id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $userInfo
     */
    private function syncUserEsiTokens(User $user, Character $mainCharacter, array $userInfo): void
    {
        $rawChars = $userInfo['characters'] ?? [];
        if (! is_array($rawChars) || $rawChars === []) {
            if (! empty($userInfo['eve_token']) && is_array($userInfo['eve_token'])) {
                $rawChars = [$userInfo['eve_token']];
            }
        }

        foreach ($rawChars as $charData) {
            if (! is_array($charData) || empty($charData['character_id'])) {
                continue;
            }

            $cid = (int) $charData['character_id'];
            $cName = (string) ($charData['character_name'] ?? '');

            if ($cid === $mainCharacter->id) {
                $char = $mainCharacter;
            } else {
                $char = Character::query()->firstOrCreate(
                    ['id' => $cid],
                    ['name' => $cName ?: 'Unknown']
                );
                $char->user()->associate($user);
                $char->save();
            }

            if (! empty($charData['character_owner_hash'])) {
                $char->character_owner_hash = (string) $charData['character_owner_hash'];
                $char->save();
            }

            if (! empty($charData['access_token']) && ! empty($charData['refresh_token'])) {
                $token = $char->esiTokens()->first();
                $expiresIn = (int) ($charData['expires_in'] ?? 1200);

                if (! $token) {
                    $token = $char->esiTokens()->create([
                        'access_token' => $charData['access_token'],
                        'refresh_token' => $charData['refresh_token'],
                        'token_type' => 'character',
                        'character_owner_hash' => $char->character_owner_hash ?? '',
                        'expires_at' => now()->addSeconds($expiresIn),
                    ]);
                } else {
                    $token->update([
                        'access_token' => $charData['access_token'],
                        'refresh_token' => $charData['refresh_token'],
                        'token_type' => 'character',
                        'character_owner_hash' => $char->character_owner_hash ?? $token->character_owner_hash,
                        'expires_at' => now()->addSeconds($expiresIn),
                    ]);
                }

                if (! empty($charData['scopes']) && is_array($charData['scopes'])) {
                    $scopeIds = EsiScope::query()
                        ->whereIn('name', $charData['scopes'])
                        ->pluck('id');
                    $token->esiScopes()->sync($scopeIds);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $userInfo
     */
    private function syncDiscordAccount(User $user, array $userInfo): void
    {
        $discord = $userInfo['discord'] ?? null;
        if (! is_array($discord) || empty($discord['id'])) {
            return;
        }

        $discordId = (string) $discord['id'];
        $username = (string) ($discord['username'] ?? 'DiscordUser');
        $displayName = isset($discord['display_name']) ? (string) $discord['display_name'] : null;
        $avatar = isset($discord['avatar']) ? (string) $discord['avatar'] : null;

        // Ensure no collision if this Discord ID was previously associated to a different user
        \App\Models\DiscordAccount::query()
            ->where('discord_user_id', $discordId)
            ->where('user_id', '!=', $user->id)
            ->delete();

        $user->discordAccount()->updateOrCreate([], [
            'discord_user_id' => $discordId,
            'username' => $username,
            'display_name' => $displayName,
            'avatar' => $avatar,
        ]);
    }
}
