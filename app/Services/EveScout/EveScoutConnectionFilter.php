<?php

declare(strict_types=1);

namespace App\Services\EveScout;

use App\Data\EveScoutConnectionData;
use App\Enums\EveScoutFilter;
use App\Models\Map;
use App\Models\Solarsystem;
use App\Services\Routing\MapProximityPathfinder;

/**
 * Keeps the sync from placing every public Thera or Turnur hole.
 *
 * The default is five stargate jumps from the map's home system and its pinned
 * systems. With no home and no pins, nothing matches.
 */
final readonly class EveScoutConnectionFilter
{
    public function __construct(private MapProximityPathfinder $pathfinder) {}

    public function allows(Map $map, EveScoutConnectionData $connection, int $hubId): bool
    {
        $destinationId = $connection->in_system_id === $hubId
            ? $connection->out_system_id
            : $connection->in_system_id;

        if ($map->eve_scout_filter === EveScoutFilter::Region) {
            $regions = array_map(intval(...), $map->eve_scout_region_ids ?? []);
            if ($regions === []) {
                return false;
            }

            $regionId = Solarsystem::query()->whereKey($destinationId)->value('region_id');

            return is_numeric($regionId) && in_array((int) $regionId, $regions, true);
        }

        $anchors = $this->anchors($map);
        if ($anchors === []) {
            return false;
        }

        return $this->pathfinder->nearest(
            $anchors,
            $destinationId,
            $map->wormholeEdges(),
            [],
            (int) $map->eve_scout_max_jumps,
        ) !== null;
    }

    /**
     * @return list<int>
     */
    private function anchors(Map $map): array
    {
        $ids = $map->mapSolarsystems()
            ->where('pinned', true)
            ->pluck('solarsystem_id')
            ->all();

        if ($map->home_solarsystem_id !== null) {
            $ids[] = $map->home_solarsystem_id;
        }

        return array_values(array_unique(array_map(intval(...), $ids)));
    }
}
