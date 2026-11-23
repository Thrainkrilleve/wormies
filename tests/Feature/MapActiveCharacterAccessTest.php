<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Models\Alliance;
use App\Models\Character;
use App\Models\Corporation;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapDiscordGrant;
use App\Models\MapDiscordServer;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

function visitorOn(Map $map): User
{
    $user = User::factory()->has(Character::factory())->create();
    $user->update(['preferred_character_id' => $user->characters->first()->id]);

    actingAs($user->fresh());

    return $user->fresh();
}

it('does not warn on a public map when the active character is not listed', function () {
    $map = Map::factory()->create(['is_public' => true]);
    User::factory()->ownsMap($map)->create();
    visitorOn($map);

    $this->get(route('maps.show', $map))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('active_character_has_access', true));
});

it('does not warn when the access list is only the owner', function () {
    $map = Map::factory()->create(['is_public' => false]);
    User::factory()->ownsMap($map)->create();
    $visitor = visitorOn($map);
    MapDiscordServer::query()->create([
        'map_id' => $map->id,
        'guild_id' => '510000000000000001',
        'guild_name' => 'Example Server',
        'status' => MapDiscordServer::Active,
        'permission' => Permission::Member->value,
    ]);
    MapDiscordGrant::query()->create([
        'map_id' => $map->id,
        'guild_id' => '510000000000000001',
        'user_id' => $visitor->id,
        'discord_user_id' => '510000000000000101',
    ]);

    $this->get(route('maps.show', $map))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('active_character_has_access', true));
});

it('treats a corporation grant as access for the active character', function () {
    $map = Map::factory()->create(['is_public' => false]);
    User::factory()->ownsMap($map)->create();
    $visitor = visitorOn($map);
    $character = $visitor->characters->first();

    MapAccess::query()->create([
        'map_id' => $map->id,
        'accessible_type' => Corporation::class,
        'accessible_id' => $character->corporation_id,
        'permission' => Permission::Member,
        'is_owner' => false,
    ]);

    $this->get(route('maps.show', $map))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('active_character_has_access', true));
});

it('treats an alliance grant as access for the active character', function () {
    $map = Map::factory()->create(['is_public' => false]);
    User::factory()->ownsMap($map)->create();
    $visitor = visitorOn($map);
    $character = $visitor->characters->first();

    MapAccess::query()->create([
        'map_id' => $map->id,
        'accessible_type' => Alliance::class,
        'accessible_id' => $character->alliance_id,
        'permission' => Permission::Member,
        'is_owner' => false,
    ]);

    $this->get(route('maps.show', $map))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('active_character_has_access', true));
});

it('warns when a restricting list covers another linked character but not the active one', function () {
    $map = Map::factory()->create(['is_public' => false]);
    User::factory()->ownsMap($map)->create();

    $user = User::factory()->has(Character::factory()->count(2))->create();
    $listed = $user->characters->first();
    $active = $user->characters->last();
    $user->update(['preferred_character_id' => $active->id]);
    actingAs($user->fresh());

    MapAccess::query()->create([
        'map_id' => $map->id,
        'accessible_type' => Character::class,
        'accessible_id' => $listed->id,
        'permission' => Permission::Member,
        'is_owner' => false,
    ]);

    $this->get(route('maps.show', $map))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('active_character_has_access', false));
});
