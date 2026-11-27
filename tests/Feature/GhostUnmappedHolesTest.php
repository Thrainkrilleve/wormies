<?php

declare(strict_types=1);

use App\Actions\MapConnections\CreateMapConnectionAction;
use App\Actions\MapSolarsystem\ReconcileGhostsAction;
use App\Actions\MapSolarsystem\UpdateMapSolarsystemAction;
use App\Actions\Signatures\ClaimSignatureAliasAction;
use App\Actions\Signatures\DeleteSignatureAction;
use App\Actions\Signatures\StoreSignatureAction;
use App\Actions\Tracking\StoreTrackingAction;
use App\Data\NewSignatureData;
use App\Data\TrackingData;
use App\Enums\SignatureCategory as SignatureCategoryEnum;
use App\Models\Map;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Models\SignatureCategory;
use App\Models\User;
use Illuminate\Validation\ValidationException;

function wormholeCategory(): SignatureCategory
{
    return SignatureCategory::query()->firstOrCreate(
        ['code' => SignatureCategoryEnum::Wormhole],
        ['name' => 'Wormhole'],
    );
}

function ghostMap(): array
{
    $map = Map::factory()->create(['ghost_unlinked_wormholes' => true]);
    $user = User::factory()->ownsMap($map)->create();
    $systemId = makeSolarsystem(30000142);
    $parent = MapSolarsystem::factory()->create([
        'map_id' => $map->id,
        'solarsystem_id' => $systemId,
        'alias' => 'A',
        'pinned' => false,
    ]);

    return [$map, $user, $parent];
}

function scanWormhole(MapSolarsystem $parent, string $scannerId): Signature
{
    return app(StoreSignatureAction::class)->handle($parent, NewSignatureData::from([
        'signature_id' => $scannerId,
        'signature_category_id' => wormholeCategory()->id,
    ]));
}

it('draws a ghost for each unmapped wormhole and removes them when the setting is off', function () {
    [$map, $user, $parent] = ghostMap();

    scanWormhole($parent, 'ABC-123');
    scanWormhole($parent, 'DEF-456');
    scanWormhole($parent, 'GHI-789');

    expect(MapSolarsystem::query()->where('map_id', $map->id)->whereNotNull('raised_by_signature_id')->count())->toBe(3)
        ->and(Signature::query()->where('map_solarsystem_id', $parent->id)->whereNotNull('map_connection_id')->count())->toBe(3);

    $map->update(['ghost_unlinked_wormholes' => false]);
    app(ReconcileGhostsAction::class)->handle($map->fresh());

    expect(MapSolarsystem::query()->where('map_id', $map->id)->whereNotNull('raised_by_signature_id')->count())->toBe(0)
        ->and(Signature::query()->where('map_solarsystem_id', $parent->id)->count())->toBe(3);
});

it('claims distinct aliases and reuses a number after its signature is deleted', function () {
    [, , $parent] = ghostMap();
    $first = scanWormhole($parent, 'ABC-123');
    $second = scanWormhole($parent, 'DEF-456');

    $claim = app(ClaimSignatureAliasAction::class);
    expect($claim->handle($first, true)['alias'])->toBe('A1')
        ->and($claim->handle($second, true)['alias'])->toBe('A2');

    app(DeleteSignatureAction::class)->handle($first);

    expect(MapSolarsystem::query()->where('raised_by_signature_id', $first->id)->exists())->toBeFalse()
        ->and(Signature::query()->find($second->id))->not->toBeNull();

    $third = scanWormhole($parent, 'JKL-012');

    expect($claim->handle($third, true)['alias'])->toBe('A1');
});

it('keeps a claimed alias when a jump resolves the ghost', function () {
    [$map, , $parent] = ghostMap();
    $signature = scanWormhole($parent, 'ABC-123');
    app(ClaimSignatureAliasAction::class)->handle($signature, true);

    $destination = makeSolarsystem(30000999);
    app(StoreTrackingAction::class)->handle(TrackingData::from([
        'from_map_solarsystem_id' => $parent->id,
        'to_solarsystem_id' => $destination,
        'signature_id' => $signature->id,
        'alias' => 'A9',
    ]));

    $resolved = MapSolarsystem::query()->where('map_id', $map->id)->where('solarsystem_id', $destination)->first();

    expect($resolved)->not->toBeNull()
        ->and($resolved->alias)->toBe('A1')
        ->and($resolved->raised_by_signature_id)->toBeNull()
        ->and(MapSolarsystem::query()->where('map_id', $map->id)->whereNotNull('raised_by_signature_id')->exists())->toBeFalse();
});

it('rejects a second connection and pinning on a ghost', function () {
    [, , $parent] = ghostMap();
    scanWormhole($parent, 'ABC-123');
    $ghost = MapSolarsystem::query()->where('hangs_off_id', $parent->id)->firstOrFail();
    $other = MapSolarsystem::factory()->create([
        'map_id' => $parent->map_id,
        'solarsystem_id' => makeSolarsystem(30000888),
        'pinned' => false,
    ]);

    expect(fn () => app(CreateMapConnectionAction::class)->handle([
        'from_map_solarsystem_id' => $other->id,
        'to_map_solarsystem_id' => $ghost->id,
    ]))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateMapSolarsystemAction::class)->handle($ghost, ['pinned' => true]))->toThrow(ValidationException::class);
});

it('still rejects a second real copy of the same system', function () {
    [$map, , $parent] = ghostMap();

    expect(fn () => MapSolarsystem::query()->create([
        'map_id' => $map->id,
        'solarsystem_id' => $parent->solarsystem_id,
        'map_solarsystem_details_id' => $parent->map_solarsystem_details_id,
        'position_x' => 400,
        'position_y' => 400,
        'pinned' => false,
    ]))->toThrow(Illuminate\Database\QueryException::class);
});
