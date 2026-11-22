<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Models\Character;
use App\Models\Corporation;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapConnection;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

it('does not grant corporation access when a character id matches that corporation id', function () {
    $sharedId = 424242;
    $corporation = Corporation::factory()->create(['id' => $sharedId]);
    $map = Map::factory()->create(['is_public' => false, 'share_token' => null]);
    MapAccess::factory()->create([
        'map_id' => $map->id,
        'accessible_type' => Corporation::class,
        'accessible_id' => $corporation->id,
        'permission' => Permission::Manager,
        'is_owner' => false,
    ]);

    $user = User::factory()->create();
    Character::factory()->for($user)->create([
        'id' => $sharedId,
        'corporation_id' => Corporation::factory(),
        'alliance_id' => null,
    ]);

    expect($map->getUserPermission($user->fresh()))->toBeNull();
});

it('rejects a signature update that points at another map connection', function () {
    $map = Map::factory()->create();
    $user = User::factory()->create();
    $character = Character::factory()->for($user)->create();
    MapAccess::factory()->create([
        'map_id' => $map->id,
        'accessible_type' => Character::class,
        'accessible_id' => $character->id,
        'permission' => Permission::Member,
        'is_owner' => false,
    ]);

    $origin = placeMapSolarsystem($map, 30013001);
    $signature = Signature::factory()->create(['map_solarsystem_id' => $origin->id]);

    $other = Map::factory()->create();
    $from = placeMapSolarsystem($other, 30013002);
    $to = placeMapSolarsystem($other, 30013003, 300, 300);
    $connection = MapConnection::factory()->create([
        'map_id' => $other->id,
        'from_map_solarsystem_id' => $from->id,
        'to_map_solarsystem_id' => $to->id,
    ]);

    actingAs($user)
        ->put(route('signatures.update', $signature), [
            'map_connection_id' => $connection->id,
        ])
        ->assertForbidden();

    expect($signature->fresh()->map_connection_id)->toBeNull();
});

it('does not let a guest change settings on a private map that merely has a share token', function () {
    $token = (string) Str::uuid();
    $map = Map::factory()->create(['is_public' => false, 'share_token' => $token]);

    $this->put(route('maps.user-settings.update', $map), ['is_pinned' => true])
        ->assertForbidden();
});

it('stores a share token in the session only when it matches', function () {
    $token = (string) Str::uuid();
    $map = Map::factory()->create(['is_public' => false, 'share_token' => $token]);
    User::factory()->ownsMap($map)->create();

    $this->get(route('maps.show', ['map' => $map, 'share_token' => 'wrong-token']))
        ->assertForbidden();

    expect(session("map_share_token_{$map->id}"))->toBeNull();

    $this->get(route('maps.show', ['map' => $map, 'share_token' => $token]))
        ->assertRedirect(route('maps.show', $map));

    expect(session("map_share_token_{$map->id}"))->toBe($token);

    $this->get(route('maps.show', $map))->assertSuccessful();
});

it('does not answer a map ping for a map the user cannot view', function () {
    $map = Map::factory()->create(['is_public' => false, 'share_token' => null]);
    $user = User::factory()->has(Character::factory())->create();

    actingAs($user)->getJson(route('maps.ping', $map))->assertForbidden();
});

it('sends security headers on a public page', function () {
    $this->get(route('landing'))
        ->assertSuccessful()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    $policy = (string) $this->get(route('landing'))->headers->get('Content-Security-Policy');

    expect($policy)->toContain("default-src 'self'")
        ->and($policy)->toContain('nonce-');
});

it('throttles repeated login page requests', function () {
    config(['services.allianceauth.only' => false]);

    foreach (range(1, 20) as $ignored) {
        $this->get(route('login'))->assertSuccessful();
    }

    $this->get(route('login'))->assertStatus(429);
});
