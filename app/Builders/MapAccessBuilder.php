<?php

declare(strict_types=1);

namespace App\Builders;

use App\Models\Alliance;
use App\Models\Character;
use App\Models\Corporation;
use App\Models\MapAccess;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * @mixin MapAccess
 *
 * @template T of MapAccess
 *
 * @extends Builder<T>
 */
final class MapAccessBuilder extends Builder
{
    /**
     * Access entries that are still valid: no expiry, or an expiry still in the future.
     */
    public function notExpired(): self
    {
        return $this->where(fn (Builder $query) => $query
            ->whereNull('expires_at')
            ->orWhere('expires_at', '>', now()));
    }

    /**
     * Match character, corporation, and alliance rows by type and id.
     * Comparing ids alone would treat a character id that happens to equal a
     * corporation id as that corporation's grant.
     */
    public function grantedTo(User $user): self
    {
        $characters = $user->characters;

        if ($characters->isEmpty()) {
            return $this->whereRaw('0 = 1');
        }

        return $this->where(function (Builder $query) use ($characters): void {
            foreach ($characters as $character) {
                $query->orWhere(function (Builder $inner) use ($character): void {
                    $inner->where(fn (Builder $grant) => $this->applyCharacter($grant, $character));
                });
            }
        });
    }

    public function forCharacter(Character $character): self
    {
        return $this->where(fn (Builder $query) => $this->applyCharacter($query, $character));
    }

    private function applyCharacter(Builder $query, Character $character): void
    {
        $query->where(fn (Builder $inner) => $inner
            ->where('accessible_type', Character::class)
            ->where('accessible_id', $character->id))
            ->orWhere(fn (Builder $inner) => $inner
                ->where('accessible_type', Corporation::class)
                ->where('accessible_id', $character->corporation_id));

        if ($character->alliance_id !== null) {
            $query->orWhere(fn (Builder $inner) => $inner
                ->where('accessible_type', Alliance::class)
                ->where('accessible_id', $character->alliance_id));
        }
    }
}
