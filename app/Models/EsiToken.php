<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\EsiTokenBuilder;
use App\Enums\EsiTokenSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Log;
use NicolasKion\Esi\Connector;

/**
 * EsiToken model representing an ESI token.
 *
 * @property int $id
 * @property string $character_owner_hash
 * @property string $access_token
 * @property string|null $refresh_token
 * @property string $token_type
 * @property EsiTokenSource $source
 * @property string|null $oauth_client_id
 * @property bool $reauth_required
 * @property int $character_id
 * @property-read  CarbonImmutable $created_at
 * @property-read  CarbonImmutable $updated_at
 * @property-read  CarbonImmutable|null $expires_at
 * @property-read Character $character
 * @property-read Collection<int, EsiScope> $scopes
 */
#[UseEloquentBuilder(EsiTokenBuilder::class)]
final class EsiToken extends Model implements \NicolasKion\Esi\Interfaces\EsiToken
{
    /**
     * Set before a deliberate delete (the pilot removed the character, or
     * EVE rejected the refresh token with invalid_grant). The ESI client
     * also calls delete() on a 401/403 and on any failed refresh, including
     * a second worker losing the single-use refresh race. That path is ignored
     * so a blip does not permanently stop tracking.
     */
    public bool $allowPermanentDeletion = false;

    protected $casts = [
        'expires_at' => 'immutable_datetime',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'source' => EsiTokenSource::class,
        'reauth_required' => 'boolean',
    ];

    /**
     * Get the character associated with the ESI token.
     *
     * @return BelongsTo<Character, $this>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /**
     * Get the scopes associated with the ESI token.
     *
     * @return BelongsToMany<EsiScope, $this>
     */
    public function esiScopes(): BelongsToMany
    {
        return $this->belongsToMany(EsiScope::class, 'esi_token_scope', 'esi_token_id', 'esi_scope_id');
    }

    /**
     * The ESI library refreshes any expired token with this app's EVE client.
     * Alliance Auth tokens belong to a different client, so the library must
     * treat them as not expired and leave the refresh to CharacterEsiClient.
     */
    public function isExpired(): bool
    {
        if ($this->source === EsiTokenSource::AllianceAuth) {
            return false;
        }

        return $this->needsRefresh();
    }

    public function needsRefresh(): bool
    {
        if (! $this->expires_at instanceof CarbonImmutable) {
            return true;
        }

        return $this->expires_at->subMinutes(5)->isPast();
    }

    public function deleteBecauseRevoked(): void
    {
        $this->allowPermanentDeletion = true;
        $this->delete();
    }

    public function getRefreshToken(): string
    {
        return $this->refresh_token;
    }

    public function getAccessToken(): string
    {
        return $this->access_token;
    }

    protected static function booted(): void
    {
        self::deleting(function (EsiToken $token): ?bool {
            if ($token->allowPermanentDeletion || ! $token->deletionCameFromEsiClient()) {
                return null;
            }

            Log::warning('ESI client tried to delete a token; keeping it so tracking can recover', [
                'character_id' => $token->character_id,
                'esi_token_id' => $token->id,
            ]);

            return false;
        });
    }

    private function deletionCameFromEsiClient(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20) as $frame) {
            if (($frame['class'] ?? null) === Connector::class) {
                return true;
            }
        }

        return false;
    }
}
