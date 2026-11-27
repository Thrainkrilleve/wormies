<?php

declare(strict_types=1);

namespace App\Actions\MapSolarsystem;

use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\Solarsystem;
use App\Support\Broadcasting\MapBroadcaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ResolveGhostSystemAction
{
    public function __construct(private MapBroadcaster $mapBroadcaster) {}

    public function handle(MapSolarsystem $ghost, Solarsystem $system, ?string $alias = null): MapSolarsystem
    {
        return DB::transaction(function () use ($ghost, $system, $alias): MapSolarsystem {
            $ghost = MapSolarsystem::query()->whereKey($ghost->id)->lockForUpdate()->firstOrFail();

            if ($ghost->raised_by_signature_id === null) {
                return $ghost;
            }

            $parent = MapSolarsystem::query()->find($ghost->hangs_off_id);
            if ($parent !== null && $parent->solarsystem_id === $system->id) {
                throw ValidationException::withMessages([
                    'solarsystem_id' => 'An unmapped hole cannot be the system it was scanned from.',
                ]);
            }

            $keptAlias = filled($ghost->alias) ? $ghost->alias : $alias;

            $existing = MapSolarsystem::query()
                ->where('map_id', $ghost->map_id)
                ->where('solarsystem_id', $system->id)
                ->whereKeyNot($ghost->id)
                ->first();

            if ($existing instanceof MapSolarsystem) {
                return $this->merge($ghost, $existing, $keptAlias);
            }

            $details = $ghost->map->mapSolarsystemDetails()->firstOrCreate([
                'solarsystem_id' => $system->id,
            ]);

            $ghost->update([
                'solarsystem_id' => $system->id,
                'map_solarsystem_details_id' => $details->id,
                'raised_by_signature_id' => null,
                'hangs_off_id' => null,
                'alias' => $keptAlias,
            ]);

            $this->broadcast($ghost);

            return $ghost->fresh();
        });
    }

    private function merge(MapSolarsystem $ghost, MapSolarsystem $existing, ?string $keptAlias): MapSolarsystem
    {
        $edge = MapConnection::query()
            ->where('from_map_solarsystem_id', $ghost->id)
            ->orWhere('to_map_solarsystem_id', $ghost->id)
            ->first();

        if ($edge instanceof MapConnection) {
            if ($edge->from_map_solarsystem_id === $ghost->id) {
                $edge->update(['from_map_solarsystem_id' => $existing->id]);
            }
            if ($edge->to_map_solarsystem_id === $ghost->id) {
                $edge->update(['to_map_solarsystem_id' => $existing->id]);
            }
        }

        if (blank($existing->alias) && filled($keptAlias)) {
            $existing->update(['alias' => $keptAlias]);
        }

        $ghostId = $ghost->id;
        $mapId = $ghost->map_id;
        $ghost->delete();

        $this->mapBroadcaster->systemsRemoved($mapId, [$ghostId], []);
        $this->broadcast($existing);

        return $existing->fresh();
    }

    private function broadcast(MapSolarsystem $system): void
    {
        $this->mapBroadcaster->systemsUpserted($system->map_id, MapSolarsystem::query()
            ->whereKey($system->id)
            ->with('details')
            ->withCount('signatures', 'wormholeSignatures', 'mapConnections', 'uncategorizedSignatures')
            ->get());
    }
}
