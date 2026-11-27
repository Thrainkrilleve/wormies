<?php

declare(strict_types=1);

namespace App\Actions\MapSolarsystem;

use App\Actions\MapConnections\CreateMapConnectionAction;
use App\Enums\LifetimeStatus;
use App\Enums\MassStatus;
use App\Enums\ShipSize;
use App\Enums\SignatureCategory as SignatureCategoryEnum;
use App\Models\Map;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Support\Broadcasting\MapBroadcaster;
use Illuminate\Support\Facades\DB;

/**
 * Draw or remove ghost nodes for wormhole signatures that have no real far side.
 * Aliases are left blank here; a bookmark claim writes them later.
 */
final readonly class ReconcileGhostsAction
{
    public function __construct(
        private CreateMapConnectionAction $createMapConnectionAction,
        private MapBroadcaster $mapBroadcaster,
    ) {}

    public function handle(Map $map): void
    {
        DB::transaction(function () use ($map): void {
            $map = Map::query()->whereKey($map->id)->lockForUpdate()->firstOrFail();

            if (! $map->ghost_unlinked_wormholes) {
                $this->drop($map, $map->mapSolarsystems()->whereNotNull('raised_by_signature_id')->get());

                return;
            }

            $this->dropUnclaimed($map);
            $this->raise($map);
        });
    }

    private function raise(Map $map): void
    {
        $signatures = Signature::query()
            ->whereHas('mapSolarsystem', fn ($query) => $query->where('map_id', $map->id)->whereNull('raised_by_signature_id'))
            ->whereNull('map_connection_id')
            ->whereHas('signatureCategory', fn ($query) => $query->where('code', SignatureCategoryEnum::Wormhole->value))
            ->orderBy('map_solarsystem_id')
            ->orderBy('id')
            ->get();

        $created = [];

        foreach ($signatures as $signature) {
            if (MapSolarsystem::query()->where('raised_by_signature_id', $signature->id)->exists()) {
                continue;
            }

            $parent = $signature->mapSolarsystem;
            $offset = MapSolarsystem::query()->where('hangs_off_id', $parent->id)->count();

            $ghost = MapSolarsystem::query()->create([
                'map_id' => $map->id,
                'solarsystem_id' => null,
                'map_solarsystem_details_id' => null,
                'raised_by_signature_id' => $signature->id,
                'hangs_off_id' => $parent->id,
                'alias' => null,
                'position_x' => $parent->position_x + 200,
                'position_y' => $parent->position_y + ($offset * 60),
                'pinned' => false,
            ]);

            $this->createMapConnectionAction->handle([
                'from_map_solarsystem_id' => $parent->id,
                'to_map_solarsystem_id' => $ghost->id,
                'mass_status' => MassStatus::Fresh,
                'ship_size' => ShipSize::Large,
                'lifetime' => LifetimeStatus::Healthy,
            ]);

            $connectionId = MapConnection::query()
                ->where('from_map_solarsystem_id', $parent->id)
                ->where('to_map_solarsystem_id', $ghost->id)
                ->value('id');

            $signature->update(['map_connection_id' => $connectionId]);
            $created[] = $ghost->id;
        }

        if ($created !== []) {
            $this->mapBroadcaster->systemsUpserted($map->id, MapSolarsystem::query()
                ->whereKey($created)
                ->with('raisedBySignature:id,signature_id')
                ->withCount('signatures', 'wormholeSignatures', 'mapConnections', 'uncategorizedSignatures')
                ->get());
        }
    }

    private function dropUnclaimed(Map $map): void
    {
        $ghosts = $map->mapSolarsystems()->whereNotNull('raised_by_signature_id')->with('raisedBySignature.signatureCategory')->get();
        $stale = $ghosts->filter(function (MapSolarsystem $ghost): bool {
            $signature = $ghost->raisedBySignature;
            if ($signature === null || $signature->signatureCategory?->code !== SignatureCategoryEnum::Wormhole) {
                return true;
            }

            $edgeId = $this->edgeId($ghost);

            return $signature->map_connection_id === null || $signature->map_connection_id !== $edgeId;
        });

        $this->drop($map, $stale);
    }

    /**
     * @param  iterable<int, MapSolarsystem>  $ghosts
     */
    private function drop(Map $map, iterable $ghosts): void
    {
        $systemIds = [];
        $connectionIds = [];

        foreach ($ghosts as $ghost) {
            $edges = MapConnection::query()
                ->where('from_map_solarsystem_id', $ghost->id)
                ->orWhere('to_map_solarsystem_id', $ghost->id)
                ->pluck('id');

            Signature::query()->whereIn('map_connection_id', $edges)->update(['map_connection_id' => null]);
            MapConnection::query()->whereIn('id', $edges)->delete();
            $systemIds[] = $ghost->id;
            foreach ($edges as $edge) {
                $connectionIds[] = $edge;
            }
            $ghost->delete();
        }

        if ($systemIds !== []) {
            $this->mapBroadcaster->systemsRemoved($map->id, $systemIds, $connectionIds);
        }
    }

    private function edgeId(MapSolarsystem $ghost): ?int
    {
        return MapConnection::query()
            ->where('from_map_solarsystem_id', $ghost->id)
            ->orWhere('to_map_solarsystem_id', $ghost->id)
            ->value('id');
    }
}
