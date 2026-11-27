<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Map;
use App\Models\MapUserSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;

use function auth;

/**
 * @mixin Map
 */
final class MapInfoResource extends JsonResource
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
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'layout' => $this->layout,
            'allow_layout_override' => $this->allow_layout_override,
            'constant_width_enabled' => $this->constant_width_enabled,
            'bookmark_format_wormhole' => $this->bookmark_format_wormhole,
            'bookmark_format_kspace' => $this->bookmark_format_kspace,
            'bookmark_format_return' => $this->bookmark_format_return,
            'bookmark_alias_scheme' => $this->bookmark_alias_scheme,
            'bookmark_ignored_alias' => $this->bookmark_ignored_alias,
            'eve_scout_sync_enabled' => $this->eve_scout_sync_enabled,
            'eve_scout_hubs' => $this->eve_scout_hubs->value,
            'eve_scout_filter' => $this->eve_scout_filter->value,
            'eve_scout_max_jumps' => $this->eve_scout_max_jumps,
            'eve_scout_region_ids' => $this->eve_scout_region_ids ?? [],
            'ghost_unlinked_wormholes' => $this->ghost_unlinked_wormholes,
            'map_user_setting' => $this->handleUserSetting(),
            'owner' => $this->mapOwner->accessible->toResource(CharacterResource::class),
        ];
    }

    /**
     * Handle the user setting for the map.
     *
     * @throws Throwable
     */
    private function handleUserSetting(): JsonResource
    {
        if ($this->mapUserSetting) {
            return $this->mapUserSetting->toResource(MapUserSettingResource::class);
        }

        $user_setting = MapUserSetting::query()->updateOrCreate([
            'user_id' => auth()->id(),
            'map_id' => $this->id,
        ]);

        return $user_setting->toResource(MapUserSettingResource::class);
    }
}
