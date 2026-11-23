<?php

declare(strict_types=1);

namespace App\Enums;

enum EveScoutHub: string
{
    case Thera = 'thera';
    case Turnur = 'turnur';
    case Both = 'both';

    /**
     * @return list<string>
     */
    public function systemNames(): array
    {
        return match ($this) {
            self::Thera => ['Thera'],
            self::Turnur => ['Turnur'],
            self::Both => ['Thera', 'Turnur'],
        };
    }
}
