<?php

declare(strict_types=1);

namespace App\Actions\MapSolarsystem;

use App\Models\MapSolarsystem;
use App\Support\Broadcasting\MapBroadcaster;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final readonly class UpdateMapSolarsystemAction
{
    public function __construct(private MapBroadcaster $mapBroadcaster) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(MapSolarsystem $mapSolarsystem, array $data): MapSolarsystem
    {
        if ($mapSolarsystem->isGhost() && ($data['pinned'] ?? false)) {
            throw ValidationException::withMessages([
                'pinned' => 'An unmapped hole cannot be pinned.',
            ]);
        }

        $placement_data = Arr::only($data, ['alias', 'position_x', 'position_y', 'pinned']);
        $details_data = Arr::only($data, ['occupier_alias', 'status', 'notes']);

        if ($placement_data !== []) {
            $mapSolarsystem->update($placement_data);
        }

        if ($details_data !== [] && $mapSolarsystem->map_solarsystem_details_id !== null) {
            // Update the loaded model (not a mass query) so the change is audited.
            $mapSolarsystem->loadMissing('details');
            $mapSolarsystem->details->update($details_data);
        }

        $this->mapBroadcaster->systemsUpserted($mapSolarsystem->map_id, MapSolarsystem::query()
            ->whereKey($mapSolarsystem->id)
            ->with('details')
            ->withCount('signatures', 'wormholeSignatures', 'mapConnections', 'uncategorizedSignatures')
            ->get());

        return $mapSolarsystem;
    }
}
