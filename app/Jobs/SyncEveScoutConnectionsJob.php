<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\EveScout\SyncEveScoutMapAction;
use App\Models\Map;
use App\Services\EveScoutService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Imports Thera and Turnur holes onto maps that opted in.
 *
 * Defaults, until a manager changes them: sync is off; hubs are both Thera and
 * Turnur; the filter keeps a hole only when its other side is within 5 stargate
 * jumps of the map's home system or a pinned system. No home and no pins means
 * nothing is added. A failed EVE-Scout response does not add or remove anything.
 */
final class SyncEveScoutConnectionsJob implements ShouldQueue
{
    use Queueable;

    /**
     * @throws Throwable
     */
    public function handle(EveScoutService $eveScout, SyncEveScoutMapAction $sync): void
    {
        $signatures = $eveScout->publicSignatures();
        if ($signatures === null) {
            return;
        }

        Map::query()
            ->where('eve_scout_sync_enabled', true)
            ->orderBy('id')
            ->each(function (Map $map) use ($sync, $signatures): void {
                $sync->handle($map, $signatures);
            });
    }
}
