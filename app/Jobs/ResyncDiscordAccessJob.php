<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DiscordAccess\DiscordAccessSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ResyncDiscordAccessJob implements ShouldQueue
{
    use Queueable;

    public function handle(DiscordAccessSync $sync): void
    {
        $sync->resyncAll();
    }
}
