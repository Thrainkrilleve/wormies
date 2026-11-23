<?php

declare(strict_types=1);

use App\Enums\EveScoutFilter;
use App\Enums\EveScoutHub;
use App\Enums\LifetimeStatus;
use App\Enums\MassStatus;
use App\Enums\Permission;
use App\Events\MapConnections\MapConnectionsRemovedEvent;
use App\Events\MapConnections\MapConnectionsUpsertedEvent;
use App\Jobs\SyncEveScoutConnectionsJob;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\Solarsystem;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

function scoutSignature(int $from, int $to, array $overrides = []): array
{
    return array_merge([
        'in_system_id' => $from,
        'out_system_id' => $to,
        'in_signature' => 'AAA-111',
        'out_signature' => 'BBB-222',
        'wh_type' => 'K162',
        'life' => 'stable',
        'mass' => 'stable',
        'remaining_hours' => 16,
        'completed' => true,
        'signature_type' => 'wormhole',
        'created_at' => null,
    ], $overrides);
}

function scoutMap(int $homeId): Map
{
    return Map::factory()->create([
        'eve_scout_sync_enabled' => true,
        'eve_scout_hubs' => EveScoutHub::Thera,
        'eve_scout_filter' => EveScoutFilter::Jumps,
        'eve_scout_max_jumps' => 0,
        'home_solarsystem_id' => $homeId,
    ]);
}

function fakeScout(array|string $signatures, int $status = 200): void
{
    Cache::flush();
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake([
        'https://api.eve-scout.com/*' => Http::response($signatures, $status),
    ]);
}

it('adds a thera hole once and refreshes mass when eve scout changes it', function () {
    Event::fake([MapConnectionsUpsertedEvent::class]);
    $theraId = makeSolarsystem(31000005);
    Solarsystem::query()->whereKey($theraId)->update(['name' => 'Thera']);
    $homeId = makeSolarsystem(30000142);
    $map = scoutMap($homeId);

    fakeScout([scoutSignature($theraId, $homeId)]);
    app(SyncEveScoutConnectionsJob::class)->handle(
        app(App\Services\EveScoutService::class),
        app(App\Actions\EveScout\SyncEveScoutMapAction::class),
    );

    expect(MapConnection::query()->where('map_id', $map->id)->count())->toBe(1)
        ->and(MapConnection::query()->where('map_id', $map->id)->first()->eve_scout_managed)->toBeTrue();

    fakeScout([scoutSignature($theraId, $homeId, [
        'mass' => 'critical',
        'remaining_hours' => 2,
    ])]);
    app(SyncEveScoutConnectionsJob::class)->handle(
        app(App\Services\EveScoutService::class),
        app(App\Actions\EveScout\SyncEveScoutMapAction::class),
    );

    $connection = MapConnection::query()->where('map_id', $map->id)->sole();

    expect(MapConnection::query()->where('map_id', $map->id)->count())->toBe(1)
        ->and($connection->mass_status)->toBe(MassStatus::Critical)
        ->and($connection->lifetime)->toBe(LifetimeStatus::EndOfLife)
        ->and($connection->eve_scout_managed)->toBeTrue();

    Event::assertDispatched(MapConnectionsUpsertedEvent::class);
});

it('removes only connections the sync created', function () {
    Event::fake([MapConnectionsRemovedEvent::class]);
    $theraId = makeSolarsystem(31000005);
    Solarsystem::query()->whereKey($theraId)->update(['name' => 'Thera']);
    $homeId = makeSolarsystem(30000142);
    $map = scoutMap($homeId);
    $from = MapSolarsystem::factory()->create([
        'map_id' => $map->id,
        'solarsystem_id' => makeSolarsystem(30001111),
        'pinned' => false,
    ]);
    $to = MapSolarsystem::factory()->create([
        'map_id' => $map->id,
        'solarsystem_id' => makeSolarsystem(30002222),
        'pinned' => false,
    ]);
    $manual = MapConnection::factory()->create([
        'map_id' => $map->id,
        'from_map_solarsystem_id' => $from->id,
        'to_map_solarsystem_id' => $to->id,
        'eve_scout_managed' => false,
    ]);

    fakeScout([scoutSignature($theraId, $homeId)]);
    app(SyncEveScoutConnectionsJob::class)->handle(
        app(App\Services\EveScoutService::class),
        app(App\Actions\EveScout\SyncEveScoutMapAction::class),
    );

    fakeScout([]);
    app(SyncEveScoutConnectionsJob::class)->handle(
        app(App\Services\EveScoutService::class),
        app(App\Actions\EveScout\SyncEveScoutMapAction::class),
    );

    expect(MapConnection::query()->where('map_id', $map->id)->where('eve_scout_managed', true)->exists())->toBeFalse()
        ->and(MapConnection::query()->find($manual->id))->not->toBeNull();

    Event::assertDispatched(MapConnectionsRemovedEvent::class);
});

it('skips holes farther than the jump filter', function () {
    $theraId = makeSolarsystem(31000005);
    Solarsystem::query()->whereKey($theraId)->update(['name' => 'Thera']);
    $homeId = makeSolarsystem(30000142);
    $farId = makeSolarsystem(30000999);
    $map = scoutMap($homeId);

    fakeScout([
        scoutSignature($theraId, $homeId, ['in_signature' => 'NEAR-1', 'out_signature' => 'NEAR-2']),
        scoutSignature($theraId, $farId, ['in_signature' => 'FAR-1', 'out_signature' => 'FAR-2']),
    ]);

    app(SyncEveScoutConnectionsJob::class)->handle(
        app(App\Services\EveScoutService::class),
        app(App\Actions\EveScout\SyncEveScoutMapAction::class),
    );

    $placed = $map->mapSolarsystems()->pluck('solarsystem_id')->all();

    expect($placed)->toContain($theraId)
        ->and($placed)->toContain($homeId)
        ->and($placed)->not->toContain($farId)
        ->and(MapConnection::query()->where('map_id', $map->id)->count())->toBe(1);
});

it('does nothing on a map that has not enabled the import', function () {
    $theraId = makeSolarsystem(31000005);
    Solarsystem::query()->whereKey($theraId)->update(['name' => 'Thera']);
    $homeId = makeSolarsystem(30000142);
    $map = scoutMap($homeId);
    $map->update(['eve_scout_sync_enabled' => false]);

    fakeScout([scoutSignature($theraId, $homeId)]);
    app(SyncEveScoutConnectionsJob::class)->handle(
        app(App\Services\EveScoutService::class),
        app(App\Actions\EveScout\SyncEveScoutMapAction::class),
    );

    expect($map->mapConnections()->count())->toBe(0)
        ->and($map->mapSolarsystems()->count())->toBe(0);
});

it('leaves the map unchanged when eve scout fails', function () {
    $theraId = makeSolarsystem(31000005);
    Solarsystem::query()->whereKey($theraId)->update(['name' => 'Thera']);
    $homeId = makeSolarsystem(30000142);
    $map = scoutMap($homeId);

    fakeScout([scoutSignature($theraId, $homeId)]);
    app(SyncEveScoutConnectionsJob::class)->handle(
        app(App\Services\EveScoutService::class),
        app(App\Actions\EveScout\SyncEveScoutMapAction::class),
    );

    $connectionId = MapConnection::query()->where('map_id', $map->id)->sole()->id;

    fakeScout('unavailable', 500);
    app(SyncEveScoutConnectionsJob::class)->handle(
        app(App\Services\EveScoutService::class),
        app(App\Actions\EveScout\SyncEveScoutMapAction::class),
    );

    expect(MapConnection::query()->find($connectionId))->not->toBeNull()
        ->and(MapConnection::query()->where('map_id', $map->id)->count())->toBe(1);
});

it('shows the import defaults on the general settings page', function () {
    $map = Map::factory()->create();
    $manager = User::factory()->ownsMap($map)->create();
    $manager->update(['preferred_character_id' => $manager->characters->first()->id]);
    $manager->refresh();

    actingAs($manager)
        ->get(route('maps.settings.general.show', $map))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('map.eve_scout_sync_enabled', false)
            ->where('map.eve_scout_hubs', 'both')
            ->where('map.eve_scout_filter', 'jumps')
            ->where('map.eve_scout_max_jumps', 5)
            ->where('map.eve_scout_region_ids', [])
            ->has('regions'));
});

it('lets a manager save the import and refuses a member', function () {
    $map = Map::factory()->create();
    $manager = User::factory()
        ->has(Character::factory()->has(MapAccess::factory(['permission' => Permission::Manager])->for($map)))
        ->create();
    $member = User::factory()
        ->has(Character::factory()->has(MapAccess::factory(['permission' => Permission::Member])->for($map)))
        ->create();

    actingAs($member)
        ->put(route('maps.settings.eve-scout.update', $map), [
            'eve_scout_sync_enabled' => true,
            'eve_scout_hubs' => 'both',
            'eve_scout_filter' => 'jumps',
            'eve_scout_max_jumps' => 5,
            'eve_scout_region_ids' => [],
        ])
        ->assertForbidden();

    expect($map->fresh()->eve_scout_sync_enabled)->toBeFalse();

    actingAs($manager)
        ->put(route('maps.settings.eve-scout.update', $map), [
            'eve_scout_sync_enabled' => true,
            'eve_scout_hubs' => 'turnur',
            'eve_scout_filter' => 'jumps',
            'eve_scout_max_jumps' => 5,
            'eve_scout_region_ids' => [],
        ])
        ->assertRedirect();

    expect($map->fresh()->eve_scout_sync_enabled)->toBeTrue()
        ->and($map->fresh()->eve_scout_hubs)->toBe(EveScoutHub::Turnur);
});

it('schedules the eve scout import every thirty minutes outside downtime', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($scheduled): bool => str_contains((string) $scheduled->description, SyncEveScoutConnectionsJob::class));

    expect($event)->not->toBeNull();

    $rejects = (new ReflectionProperty($event, 'rejects'))->getValue($event);

    expect($event->expression)->toBe('*/30 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($rejects)->not->toBeEmpty();
});
