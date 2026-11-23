<?php

declare(strict_types=1);

namespace App\Features;

use App\Enums\Permission;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapDiscordServer;
use App\Models\User;
use Inertia\ProvidesInertiaProperties;
use Inertia\RenderContext;

final readonly class MapPermissionsFeature implements ProvidesInertiaProperties
{
    public function __construct(
        private Map $map,
        private ?User $user,
    ) {}

    public function toInertiaProperties(RenderContext $context): array
    {
        $permission = $this->user instanceof User
            ? $this->map->getUserPermission($this->user)
            : ($this->map->isPubliclyAccessible() ? Permission::Viewer : null);

        return [
            'permission' => $permission?->value,
            'active_character_has_access' => $this->user instanceof User && $this->activeCharacterHasAccess(),
        ];
    }

    /**
     * The status-bar warning is for a map that actually limits who may use it.
     * A public map, or one whose only entry is the owner, does not. A character
     * is allowed when the list names that character, its corporation, its
     * alliance, or the user (a Discord grant). Characters linked to the same
     * account are checked the same way; a grant on a different character does
     * not cover the one that is active.
     */
    private function activeCharacterHasAccess(): bool
    {
        if (! $this->mapRestrictsAccess()) {
            return true;
        }

        $activeCharacter = $this->user?->active_character;

        if (! $activeCharacter instanceof Character) {
            return false;
        }

        if ($this->map->mapAccessors()->notExpired()->forCharacter($activeCharacter)->exists()) {
            return true;
        }

        return $this->hasUserGrant();
    }

    /**
     * True when a non-owner entry is currently in force. The owner row is
     * created with every map and is not, by itself, a restriction.
     */
    private function mapRestrictsAccess(): bool
    {
        if ($this->map->is_public) {
            return false;
        }

        return $this->map->mapAccessors()
            ->notExpired()
            ->where('is_owner', false)
            ->exists();
    }

    private function hasUserGrant(): bool
    {
        if (! $this->user instanceof User) {
            return false;
        }

        return $this->map->discordGrants()
            ->where('map_discord_grants.user_id', $this->user->id)
            ->join('map_discord_servers', function ($join): void {
                $join->on('map_discord_servers.map_id', '=', 'map_discord_grants.map_id')
                    ->on('map_discord_servers.guild_id', '=', 'map_discord_grants.guild_id');
            })
            ->where('map_discord_servers.status', MapDiscordServer::Active)
            ->exists();
    }
}
