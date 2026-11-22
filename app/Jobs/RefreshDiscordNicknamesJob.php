<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DiscordAccess\DiscordNicknameSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RefreshDiscordNicknamesJob implements ShouldQueue
{
    use Queueable;

    public function handle(DiscordNicknameSync $nicknames): void
    {
        $nicknames->refreshAll();
    }
}
