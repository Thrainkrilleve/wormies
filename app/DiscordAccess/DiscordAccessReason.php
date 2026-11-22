<?php

declare(strict_types=1);

namespace App\DiscordAccess;

final class DiscordAccessReason
{
    public const string Join = 'join';

    public const string Leave = 'leave';

    public const string Resync = 'resync';

    public const string Link = 'link';

    public const string Unlink = 'unlink';

    public const string AaIdChange = 'aa_id_change';

    public const string BotRemoved = 'bot_removed';
}
