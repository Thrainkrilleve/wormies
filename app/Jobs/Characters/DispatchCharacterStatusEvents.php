<?php

declare(strict_types=1);

namespace App\Jobs\Characters;

use App\Events\Characters\CharacterStatusUpdatedEvent;
use App\Events\Characters\UserCharacterStatusUpdatedEvent;
use App\Models\Character;
use App\Models\CharacterStatus;
use App\Models\Map;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class DispatchCharacterStatusEvents implements ShouldQueue
{
    use Queueable;

    public int $timeout = 25;

    public int $tries = 2;

    public function __construct()
    {
        $this->onQueue('tracking');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $updatedCharacterIds = DB::transaction(function () {
            $rows = CharacterStatus::query()
                ->whereNotNull('event_queued_at')
                ->lockForUpdate()
                ->get(['id', 'character_id']);

            if ($rows->isEmpty()) {
                return collect();
            }

            CharacterStatus::query()
                ->whereIn('id', $rows->pluck('id'))
                ->update(['event_queued_at' => null]);

            return $rows->pluck('character_id')->unique()->values();
        });

        if ($updatedCharacterIds->isEmpty()) {
            return;
        }

        Map::query()
            ->whereHas('mapUserSettings', fn (Builder $query) => $query
                ->where('tracking_allowed', true)
                ->whereHas('user.characters', fn (Builder $query) => $query->whereIn('id', $updatedCharacterIds))
            )
            ->select('maps.id')
            ->lazy()
            ->each(fn (Map $map) => CharacterStatusUpdatedEvent::dispatch($map->id));

        Character::query()
            ->whereIn('id', $updatedCharacterIds)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique()
            ->each(fn (int $userId) => UserCharacterStatusUpdatedEvent::dispatch($userId));
    }
}
