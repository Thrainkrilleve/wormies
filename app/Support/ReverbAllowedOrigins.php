<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reverb compares the websocket Origin header's host, not the full URL.
 * Allowed entries are stored as hosts so a scheme-qualified APP_URL matches.
 */
final class ReverbAllowedOrigins
{
    /**
     * @return list<string>
     */
    public static function fromConfig(?string $appUrl, ?string $extraOrigins): array
    {
        $entries = [];

        if (is_string($appUrl) && mb_trim($appUrl) !== '') {
            $entries[] = mb_trim($appUrl);
        }

        foreach (explode(',', (string) $extraOrigins) as $origin) {
            $entries[] = $origin;
        }

        return self::normalize($entries);
    }

    /**
     * Host patterns for Str::is. '*' is returned only when nothing was configured.
     * A URL that has no host is dropped and does not become '*'.
     * A value without a scheme, including a wildcard such as *.example.com, is kept.
     *
     * @param  list<string>  $entries
     * @return list<string>
     */
    public static function normalize(array $entries): array
    {
        $configured = [];

        foreach ($entries as $entry) {
            $entry = mb_trim($entry);

            if ($entry !== '') {
                $configured[] = $entry;
            }
        }

        if ($configured === []) {
            return ['*'];
        }

        $hosts = [];

        foreach ($configured as $entry) {
            $host = self::host($entry);

            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    private static function host(string $entry): string
    {
        if (! str_contains($entry, '://')) {
            return $entry;
        }

        $host = parse_url($entry, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }
}
