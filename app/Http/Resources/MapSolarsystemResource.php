<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\MapSolarsystemStatus;
use App\Models\MapSolarsystem;
use App\Models\MapSolarsystemDetails;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;

/**
 * @mixin MapSolarsystem
 */
final class MapSolarsystemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     *
     * @throws Throwable
     */
    public function toArray(Request $request): array
    {
        $details = $this->getRelationValue('details');

        return [
            'id' => $this->id,
            'map_id' => $this->map_id,
            'alias' => $this->alias,
            'status' => $details instanceof MapSolarsystemDetails ? $details->status : MapSolarsystemStatus::Unknown->value,
            'occupier_alias' => $details instanceof MapSolarsystemDetails ? $details->occupier_alias : null,
            'position' => $this->getPositionArray(),
            'pinned' => $this->pinned,
            'solarsystem_id' => $this->solarsystem_id,
            'is_ghost' => $this->isGhost(),
            'ghost_label' => $this->raisedBySignature?->signature_id,
            'hangs_off_id' => $this->hangs_off_id,
            'raised_by_signature_id' => $this->raised_by_signature_id,
            'signatures_count' => $this->signatures_count,
            'uncategorized_signatures_count' => $this->uncategorized_signatures_count,
            'wormhole_signatures_count' => $this->wormhole_signatures_count,
            'map_connections_count' => $this->map_connections_count,
            'threat_level' => $this->whenLoaded('wormholeSystem', fn () => $this->wormholeSystem?->threat_level),
            'signatures' => $this->whenLoaded('signatures', fn () => $this->signatures->toResourceCollection(MapSignatureResource::class)),
        ];
    }

    /**
     * @return array{x: int, y: int}
     */
    private function getPositionArray(): array
    {
        return [
            'x' => $this->position_x,
            'y' => $this->position_y,
        ];
    }
}
