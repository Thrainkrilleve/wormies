<?php

declare(strict_types=1);

namespace App\Enums;

enum EsiTokenSource: string
{
    case EveSso = 'eve_sso';
    case AllianceAuth = 'alliance_auth';
}
