<?php

declare(strict_types=1);

namespace App\DiscordAccess;

/**
 * One-time OAuth state for a map invite. The raw value lives in the manager's
 * session. The database stores only its hash.
 */
final class DiscordAccessState
{
    public const string SessionKey = 'discord_access_oauth_state';

    public static function issue(): string
    {
        return mb_rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $state): string
    {
        return hash('sha256', $state);
    }
}
