<?php

declare(strict_types=1);

namespace App\Console\Commands\Characters;

use App\Console\Commands\AppCommand;
use App\Jobs\Characters\DispatchCharacterStatusEvents;
use App\Jobs\Characters\UpdateCharacterLocation;
use App\Models\CharacterStatus;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

use function sprintf;

final class GetOnlineCharacterLocationsCommand extends AppCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:get-online-character-locations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Checks the location of online characters and updates their status in the database';

    /**
     * Execute the console command.
     *
     * @throws Throwable
     */
    public function handle(): void
    {
        $characters = CharacterStatus::query()
            ->isOnline()
            ->hasRequiredScopes()
            ->get();

        $dropped = CharacterStatus::query()
            ->doesntHaveRequiredScopes()
            ->where('is_online', true)
            ->update([
                'is_online' => false,
                'event_queued_at' => now(),
            ]);

        if ($dropped > 0) {
            Log::warning('Marked characters offline because their tracking scopes are gone', [
                'count' => $dropped,
            ]);
            DispatchCharacterStatusEvents::dispatch();
        }

        if ($characters->isEmpty()) {
            $this->info('No online characters to process.');

            return;
        }

        $this->info(sprintf('Dispatching jobs for %d characters...', $characters->count()));

        $jobs = $characters->map(fn (CharacterStatus $characterStatus): UpdateCharacterLocation => new UpdateCharacterLocation($characterStatus->id));

        $batch = Bus::batch($jobs->all())
            ->name('Update Character Locations')
            ->finally(function (Batch $batch): void {
                // Dispatch a job to handle event dispatching after all character updates are complete
                DispatchCharacterStatusEvents::dispatch();
            })
            ->dispatch();

        $this->info(sprintf('Batch dispatched with ID: %s', $batch->id));
        $this->info('Characters will be processed in parallel.');
    }
}
