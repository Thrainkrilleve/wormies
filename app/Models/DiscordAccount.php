<?php

declare(strict_types=1);

namespace App\Models;

use App\DiscordAccess\DiscordAccessReason;
use App\DiscordAccess\DiscordAccessSync;
use Database\Factories\DiscordAccountFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(DiscordAccountFactory::class)]
final class DiscordAccount extends Model
{
    /** @use HasFactory<DiscordAccountFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        self::updating(function (DiscordAccount $account): void {
            if (! $account->isDirty('discord_user_id')) {
                return;
            }

            $original = $account->getOriginal('discord_user_id');
            if (is_string($original) && $original !== '' && $original !== $account->discord_user_id) {
                app(DiscordAccessSync::class)->revokeDiscordUser($original, DiscordAccessReason::AaIdChange);
            }
        });

        self::deleting(function (DiscordAccount $account): void {
            app(DiscordAccessSync::class)->revokeDiscordUser($account->discord_user_id, DiscordAccessReason::Unlink);
        });
    }
}
