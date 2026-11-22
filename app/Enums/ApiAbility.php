<?php

declare(strict_types=1);

namespace App\Enums;

enum ApiAbility: string
{
    case MapsRead = 'maps:read';
    case MapsWrite = 'maps:write';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
