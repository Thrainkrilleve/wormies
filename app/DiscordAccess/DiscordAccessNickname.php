<?php

declare(strict_types=1);

namespace App\DiscordAccess;

/**
 * Discord server nicknames are at most 32 characters. Tickers are kept and the
 * character name is what gets shortened.
 */
final class DiscordAccessNickname
{
    public const int Limit = 32;

    public static function format(?string $allianceTicker, ?string $corporationTicker, string $characterName): string
    {
        $allianceTicker = self::ticker($allianceTicker);
        $corporationTicker = self::ticker($corporationTicker);
        $prefix = '';
        if ($allianceTicker !== null) {
            $prefix .= '['.$allianceTicker.'] ';
        }
        if ($corporationTicker !== null) {
            $prefix .= '['.$corporationTicker.'] ';
        }

        if (mb_strlen($prefix) > self::Limit) {
            return mb_rtrim(mb_substr($prefix, 0, self::Limit));
        }

        $name = mb_trim($characterName);
        $room = self::Limit - mb_strlen($prefix);

        return mb_rtrim($prefix.mb_substr($name, 0, $room));
    }

    private static function ticker(?string $ticker): ?string
    {
        if (! is_string($ticker)) {
            return null;
        }

        $ticker = mb_trim($ticker);

        return $ticker === '' ? null : $ticker;
    }
}
