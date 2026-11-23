<?php

declare(strict_types=1);

namespace App\Services\Esi;

use App\Enums\EsiTokenSource;
use App\Models\Character;
use App\Models\EsiScope;
use App\Models\EsiToken;
use App\Services\AllianceAuthService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use NicolasKion\Esi\DTO\EsiError;
use NicolasKion\Esi\DTO\EsiResult;
use NicolasKion\Esi\Esi;
use Throwable;

/**
 * Serialized ESI calls for one character.
 *
 * EVE refresh tokens are single-use. Location and online jobs used to refresh
 * the same token at the same time; the loser got invalid_grant and the ESI
 * client deleted the token, which dropped the character out of tracking until
 * somebody connected it again. The lock makes the refresh happen once.
 */
final class CharacterEsiClient
{
    public const ERROR_LIMIT_KEY = 'esi:error-limit';

    public const BROADCAST_INTERVAL_SECONDS = 60;

    public function __construct(
        private readonly Esi $esi,
        private readonly AllianceAuthService $allianceAuth,
    ) {}

    /**
     * Broadcast a location heartbeat at most once a minute when nothing else changed,
     * so a browser that missed one event catches up without waiting for the next jump.
     */
    public static function shouldBroadcast(int $characterStatusId, bool $changed): bool
    {
        $key = 'character-status:broadcast:'.$characterStatusId;

        if ($changed) {
            Cache::put($key, true, now()->addSeconds(self::BROADCAST_INTERVAL_SECONDS));

            return true;
        }

        if (Cache::has($key)) {
            return false;
        }

        Cache::put($key, true, now()->addSeconds(self::BROADCAST_INTERVAL_SECONDS));

        return true;
    }

    public function location(Character $character): EsiResult
    {
        return $this->call($character, fn (): EsiResult => $this->esi->getLocation($character));
    }

    public function ship(Character $character): EsiResult
    {
        return $this->call($character, fn (): EsiResult => $this->esi->getShip($character));
    }

    public function online(Character $character): EsiResult
    {
        return $this->call($character, fn (): EsiResult => $this->esi->getOnline($character));
    }

    /**
     * @param  callable(): EsiResult  $callback
     */
    private function call(Character $character, callable $callback): EsiResult
    {
        if (Cache::has(self::ERROR_LIMIT_KEY)) {
            $this->warnOnce($character, 'Skipping ESI call while the error-limit backoff is active');

            return EsiResult::fromError(new EsiError(420, 'error limit backoff'));
        }

        try {
            /** @var EsiResult $result */
            $result = Cache::lock('esi-character:'.$character->id, 25)->block(
                20,
                function () use ($character, $callback): EsiResult {
                    $this->refreshExpiredTokens($character);
                    $character->unsetRelation('esiTokens');

                    return $callback();
                },
            );
        } catch (LockTimeoutException) {
            $this->warnOnce($character, 'ESI character lock was still held; the next poll will retry');

            return EsiResult::fromError(new EsiError(429, 'character lock timeout'));
        } catch (Throwable $exception) {
            $this->warnOnce($character, 'ESI call failed before a response: '.$exception->getMessage());

            return EsiResult::fromError(new EsiError(500, $exception->getMessage()));
        }

        $this->rememberErrorLimit($result);

        if ($result->failed() && $result->error !== null) {
            $this->warnOnce(
                $character,
                sprintf(
                    'ESI responded %d: %s',
                    $result->error->code,
                    mb_substr((string) $result->error->body, 0, 180),
                ),
            );
        }

        return $result;
    }

    private function refreshExpiredTokens(Character $character): void
    {
        foreach ($character->esiTokens()->get() as $token) {
            if (! $token->needsRefresh()) {
                continue;
            }

            $this->refreshToken($token);
        }
    }

    private function refreshToken(EsiToken $token): void
    {
        if ($token->source === EsiTokenSource::AllianceAuth) {
            $this->refreshFromAllianceAuth($token);

            return;
        }

        $this->refreshFromEveSso($token);
    }

    private function refreshFromAllianceAuth(EsiToken $token): void
    {
        $fresh = $this->allianceAuth->freshCharacterAccessToken((int) $token->character_id);

        if ($fresh === null) {
            $this->markReauthRequired($token);

            return;
        }

        $token->update([
            'access_token' => $fresh['access_token'],
            'expires_at' => now()->addSeconds($fresh['expires_in']),
            'reauth_required' => false,
        ]);

        if ($fresh['scopes'] !== []) {
            $scopeIds = EsiScope::query()->whereIn('name', $fresh['scopes'])->pluck('id');
            $token->esiScopes()->sync($scopeIds);
        }
    }

    private function refreshFromEveSso(EsiToken $token): void
    {
        if ($token->refresh_token === null || $token->refresh_token === '') {
            Log::warning('ESI token has no refresh token', [
                'character_id' => $token->character_id,
                'esi_token_id' => $token->id,
            ]);

            return;
        }

        $clientId = (string) config('esi.client_id');

        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config('esi.user_agent'),
            ])
                ->asForm()
                ->withBasicAuth($clientId, (string) config('esi.client_secret'))
                ->retry(
                    3,
                    500,
                    fn (Throwable $exception, PendingRequest $request): bool => $exception instanceof RequestException && $exception->response->status() >= 500,
                    throw: false,
                )
                ->post('https://login.eveonline.com/v2/oauth/token', [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $token->getRefreshToken(),
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('ESI token refresh could not reach the login server', [
                'character_id' => $token->character_id,
                'esi_token_id' => $token->id,
                'exception' => $exception->getMessage(),
            ]);

            return;
        }

        if ($response->failed()) {
            Log::warning('ESI token refresh failed', [
                'character_id' => $token->character_id,
                'esi_token_id' => $token->id,
                'status' => $response->status(),
            ]);

            if ($response->status() === 400 && str_contains($response->body(), 'invalid_grant') && $this->refreshedWithMatchingClient($token, $clientId)) {
                $token->deleteBecauseRevoked();
            }

            return;
        }

        $expiresIn = $response->json('expires_in');
        $refreshToken = $response->json('refresh_token');

        $token->update([
            'access_token' => $response->json('access_token'),
            'expires_at' => now()->addSeconds(is_numeric($expiresIn) ? (int) $expiresIn : 1200),
            'refresh_token' => is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : $token->refresh_token,
        ]);
    }

    private function refreshedWithMatchingClient(EsiToken $token, string $clientId): bool
    {
        if ($clientId === '') {
            return false;
        }

        $issuedTo = $token->oauth_client_id;

        if ($issuedTo === null || $issuedTo === '') {
            return true;
        }

        return hash_equals($issuedTo, $clientId);
    }

    private function markReauthRequired(EsiToken $token): void
    {
        if ($token->reauth_required) {
            return;
        }

        $token->update(['reauth_required' => true]);
        Log::warning('Alliance Auth ESI token needs a direct EVE login', [
            'character_id' => $token->character_id,
            'esi_token_id' => $token->id,
        ]);
    }

    private function rememberErrorLimit(EsiResult $result): void
    {
        $error = $result->error;

        if ($error === null || $error->code !== 420) {
            return;
        }

        $reset = $result->stats instanceof \NicolasKion\Esi\DTO\EsiStats
            ? $result->stats->error_limit_reset
            : null;
        $seconds = max(1, (int) ($reset ?? 30));
        Cache::put(self::ERROR_LIMIT_KEY, true, now()->addSeconds($seconds));
        Log::warning('ESI error limit reached; backing off', ['seconds' => $seconds]);
    }

    private function warnOnce(Character $character, string $message): void
    {
        $key = 'esi-warn:'.$character->id.':'.sha1($message);

        if (! Cache::add($key, true, now()->addMinutes(5))) {
            return;
        }

        Log::warning($message, ['character_id' => $character->id]);
    }
}
