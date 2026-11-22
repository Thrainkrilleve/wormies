<?php

declare(strict_types=1);

namespace App\Console\Commands\Killmails;

use App\Console\Commands\AppCommand;
use App\Models\Killmail;
use Illuminate\Container\Attributes\Config;

final class PurgeOldKillmailsCommand extends AppCommand
{
    /**
     * @var string
     */
    protected $signature = 'app:purge-old-killmails';

    /**
     * @var string
     */
    protected $description = 'Purge old killmails from the database';

    public function handle(
        #[Config('services.zkillboard.max_age_days')] int $max_age_days,
    ): int {
        $cutoff = now()->subDays($max_age_days);
        $total = 0;

        do {
            $ids = Killmail::query()
                ->where('time', '<', $cutoff)
                ->orderBy('id')
                ->limit(500)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $total += Killmail::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === 500);

        $this->info("Deleted {$total} killmails older than {$max_age_days} days.");

        return self::SUCCESS;
    }
}
