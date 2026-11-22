<?php

declare(strict_types=1);

namespace App\DiscordAccess;

/**
 * The guild Discord named in a bot-scope token response. The query string is not a source.
 */
final class DiscordAccessAuthorization
{
    public function __construct(public readonly string $guildId) {}
}
