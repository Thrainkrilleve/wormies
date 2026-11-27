<?php

declare(strict_types=1);

namespace App\Actions\Signatures;

use App\Models\Map;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Support\Alias\NextAlias;
use App\Support\Broadcasting\MapBroadcaster;
use Illuminate\Support\Facades\DB;

/**
 * Write the next chain alias onto the ghost for this signature.
 * Viewers receive the name and nothing is stored. Two claims take the map
 * lock so they cannot both keep the same number.
 */
final readonly class ClaimSignatureAliasAction
{
    public function __construct(private MapBroadcaster $mapBroadcaster) {}

    /**
     * @return array{alias: string|null, claimed: bool}
     */
    public function handle(Signature $signature, bool $persist): array
    {
        return DB::transaction(function () use ($signature, $persist): array {
            $parent = $signature->mapSolarsystem()->firstOrFail();
            Map::query()->whereKey($parent->map_id)->lockForUpdate()->first();

            $ghost = MapSolarsystem::query()
                ->where('raised_by_signature_id', $signature->id)
                ->lockForUpdate()
                ->first();

            if ($ghost instanceof MapSolarsystem && filled($ghost->alias)) {
                return ['alias' => $ghost->alias, 'claimed' => true];
            }

            $map = $parent->map;
            $aliases = MapSolarsystem::query()
                ->where('map_id', $map->id)
                ->whereNotNull('alias')
                ->pluck('alias')
                ->all();

            $alias = NextAlias::guess(
                $parent->alias,
                $aliases,
                $map->bookmark_alias_scheme->value,
                $map->bookmark_ignored_alias,
            );

            if ($ghost instanceof MapSolarsystem && $persist) {
                $ghost->update(['alias' => $alias]);
                $this->mapBroadcaster->systemsUpserted($map->id, MapSolarsystem::query()
                    ->whereKey($ghost->id)
                    ->with('raisedBySignature:id,signature_id')
                    ->withCount('signatures', 'wormholeSignatures', 'mapConnections', 'uncategorizedSignatures')
                    ->get());

                return ['alias' => $alias, 'claimed' => true];
            }

            return ['alias' => $alias, 'claimed' => false];
        });
    }
}
