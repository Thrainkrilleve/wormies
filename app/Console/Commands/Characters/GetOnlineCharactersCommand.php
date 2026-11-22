<?php

declare(strict_types=1);

namespace App\Console\Commands\Characters;

use App\Console\Commands\AppCommand;
use App\Jobs\Characters\DispatchCharacterStatusEvents;
use App\Jobs\Characters\UpdateCharacterOnlineStatus;
use App\Models\CharacterStatus;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Throwable;

use function sprintf;

final class GetOnlineCharactersCommand extends AppCommand
{
    /**
     * @var string
     */
    protected $signature = 'app:get-online-characters';

    /**
     * @var string
     */
    protected $description = 'Checks the online status of characters and updates their status in the database';

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $characters = CharacterStatus::query()
            ->hasRequiredScopes()
            ->where(function (Builder $query): void {
                $query->where('is_online', true)
                    ->orWhere('last_online_at', '>=', now()->subHours(6))
                    ->orWhereHas('character.user', fn (Builder $user) => $user->where('last_active_at', '>=', now()->subMinutes(30)));
            })
            ->get();

        if ($characters->isEmpty()) {
            $this->info('No characters to check.');

            return;
        }

        $this->info(sprintf('Dispatching jobs for %d characters...', $characters->count()));

        $jobs = $characters->map(fn (CharacterStatus $characterStatus): UpdateCharacterOnlineStatus => new UpdateCharacterOnlineStatus($characterStatus->id));

        $batch = Bus::batch($jobs->all())
            ->name('Update Character Online Status')
            ->finally(function (Batch $batch): void {
                DispatchCharacterStatusEvents::dispatch();
            })
            ->dispatch();

        $this->info(sprintf('Batch dispatched with ID: %s', $batch->id));
        $this->info('Characters will be processed in parallel.');
    }
}
