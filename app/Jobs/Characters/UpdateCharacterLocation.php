<?php

declare(strict_types=1);

namespace App\Jobs\Characters;

use App\Actions\MapConnections\RecordMapConnectionJumpAction;
use App\Actions\ShipHistories\UpdateShipHistoryAction;
use App\Models\CharacterStatus;
use App\Services\Esi\CharacterEsiClient;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use NicolasKion\Esi\DTO\EsiResult;
use Throwable;

final class UpdateCharacterLocation implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 25;

    public int $tries = 2;

    public int $uniqueFor = 30;

    public function __construct(public int $character_status_id)
    {
        $this->onQueue('tracking');
    }

    public function uniqueId(): string
    {
        return (string) $this->character_status_id;
    }

    /**
     * @throws Throwable
     */
    public function handle(CharacterEsiClient $esi, UpdateShipHistoryAction $action, RecordMapConnectionJumpAction $recordJumpAction): void
    {
        $characterStatus = CharacterStatus::query()->find($this->character_status_id);

        if ($characterStatus === null) {
            return;
        }

        $locationRequest = $esi->location($characterStatus->character);

        if ($this->characterIsOffline($locationRequest)) {
            $characterStatus->update([
                'is_online' => false,
                'location_last_checked_at' => now(),
                'event_queued_at' => now(),
            ]);

            return;
        }

        if ($locationRequest->failed()) {
            $characterStatus->update(['location_last_checked_at' => now()]);

            return;
        }

        $shipRequest = $esi->ship($characterStatus->character);

        if ($shipRequest->failed()) {
            $characterStatus->update(['location_last_checked_at' => now()]);

            return;
        }

        $location = $locationRequest->data;
        $ship = $shipRequest->data;
        $previousSolarsystemId = $characterStatus->solarsystem_id;

        $characterStatus->update([
            'solarsystem_id' => $location->solar_system_id,
            'station_id' => $location->station_id,
            'structure_id' => $location->structure_id,
            'ship_name' => $ship->ship_name,
            'ship_type_id' => $ship->ship_type_id,
            'ship_item_id' => $ship->ship_item_id,
        ]);

        $changed = $characterStatus->wasChanged();

        try {
            $action->handle(
                $characterStatus->character_id,
                $ship->ship_item_id,
                $ship->ship_type_id,
                $ship->ship_name
            );
        } catch (Throwable $exception) {
            Log::warning(sprintf('Failed to update ship history for character %d', $characterStatus->character_id), [
                'exception' => $exception->getMessage(),
            ]);
        }

        $characterStatus->update([
            'location_last_checked_at' => now(),
            ...(CharacterEsiClient::shouldBroadcast($characterStatus->id, $changed) ? ['event_queued_at' => now()] : []),
        ]);

        $this->recordJump($recordJumpAction, $characterStatus, $previousSolarsystemId, $location->solar_system_id, $ship->ship_type_id, $ship->ship_name);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping('character:'.$this->character_status_id)->dontRelease()->expireAfter(30)];
    }

    private function characterIsOffline(EsiResult $result): bool
    {
        return $result->failed()
            && $result->error?->code === 400
            && str_contains(mb_strtolower((string) $result->error->body), 'not online');
    }

    private function recordJump(
        RecordMapConnectionJumpAction $recordJumpAction,
        CharacterStatus $characterStatus,
        ?int $previousSolarsystemId,
        int $solarSystemId,
        int $shipTypeId,
        string $shipName,
    ): void {
        if ($previousSolarsystemId === null || $previousSolarsystemId === $solarSystemId) {
            return;
        }

        try {
            $recordJumpAction->handle(
                $characterStatus->character_id,
                $previousSolarsystemId,
                $solarSystemId,
                $shipTypeId,
                $shipName,
            );
        } catch (Throwable $exception) {
            Log::warning(sprintf('Failed to record connection jump for character %d', $characterStatus->character_id), [
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
