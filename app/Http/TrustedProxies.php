<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Parses TRUSTED_PROXIES. "*" trusts every hop. Anything else is a comma-separated
 * list of IPs and CIDRs. The default covers loopback and the private ranges Docker uses.
 */
final class TrustedProxies
{
    public const DEFAULT = '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16';

    /**
     * @return list<string>|string
     */
    public static function configured(): array|string
    {
        $value = config('app.trusted_proxies');

        if (is_string($value) || is_array($value)) {
            /** @var list<string>|string $value */
            return $value;
        }

        return self::fromEnv(null);
    }

    /**
     * @return list<string>|string
     */
    public static function fromEnv(?string $value): array|string
    {
        $value = mb_trim((string) ($value ?? self::DEFAULT));

        if ($value === '' || $value === '*') {
            return $value === '*' ? '*' : self::fromEnv(self::DEFAULT);
        }

        $proxies = array_values(array_filter(array_map(trim(...), explode(',', $value)), fn (string $proxy): bool => $proxy !== ''));

        return $proxies === [] ? explode(',', self::DEFAULT) : $proxies;
    }
}
