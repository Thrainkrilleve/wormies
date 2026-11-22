<?php

declare(strict_types=1);

namespace App\Jobs\Characters;

use App\Models\CharacterStatus;
use App\Services\Esi\CharacterEsiClient;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

use function now;

final class UpdateCharacterOnlineStatus implements ShouldBeUnique, ShouldQueue
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

    public function handle(CharacterEsiClient $esi): void
    {
        $characterStatus = CharacterStatus::query()->find($this->character_status_id);

        if ($characterStatus === null) {
            return;
        }

        $request = $esi->online($characterStatus->character);

        if ($request->failed()) {
            return;
        }

        $online = (bool) $request->data->online;
        $onlineStatusChanged = $characterStatus->is_online !== $online;

        $characterStatus->update([
            'is_online' => $online,
            'last_online_at' => $online ? now() : $characterStatus->last_online_at,
            'online_last_checked_at' => now(),
            ...($onlineStatusChanged ? ['event_queued_at' => now()] : []),
        ]);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping('character:'.$this->character_status_id)->dontRelease()->expireAfter(30)];
    }
}
