<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Map;
use App\Models\User;

final class MapPolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(?User $user, Map $map): bool
    {
        if ($map->is_public) {
            return true;
        }

        if ($this->shareTokenAllows($map)) {
            return true;
        }

        if (! $user instanceof User) {
            return false;
        }

        return $map->mapAccessors()->notExpired()->grantedTo($user)->exists();
    }

    public function viewCharacters(?User $user, Map $map): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $permission = $map->getUserPermission($user);

        return $permission instanceof Permission && $permission->isAtLeast(Permission::Member);
    }

    public function create(): bool
    {
        return true;
    }

    public function update(?User $user, Map $map): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $permission = $map->getUserPermission($user);

        return $permission instanceof Permission && $permission->isAtLeast(Permission::Member);
    }

    public function manageAccess(?User $user, Map $map): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $permission = $map->getUserPermission($user);

        return $permission instanceof Permission && $permission->isAtLeast(Permission::Manager);
    }

    /**
     * Manager-level map configuration that affects every viewer (e.g. the layout mode).
     */
    public function updateSettings(?User $user, Map $map): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $permission = $map->getUserPermission($user);

        return $permission instanceof Permission && $permission->isAtLeast(Permission::Manager);
    }

    public function delete(?User $user, Map $map): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $map->mapAccessors()
            ->notExpired()
            ->grantedTo($user)
            ->where('is_owner', true)
            ->exists();
    }

    private function shareTokenAllows(Map $map): bool
    {
        $expected = $map->share_token;

        if (! is_string($expected) || $expected === '') {
            return false;
        }

        $provided = request()->query('share_token') ?? session("map_share_token_{$map->id}");

        if (! is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }
}
