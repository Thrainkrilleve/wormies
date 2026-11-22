<?php

declare(strict_types=1);

namespace App\Services\Esi;

use App\Enums\EsiTokenSource;
use App\Models\EsiToken;

/**
 * Tokens copied out of Alliance Auth were stored with token_type "character"
 * and no record of which OAuth client issued them. Those rows must use the
 * Alliance Auth refresh path. Direct EVE SSO tokens use token_type Bearer.
 */
final class RepairAllianceAuthEsiTokens
{
    public static function markCharacterTypedTokens(): int
    {
        return EsiToken::query()
            ->where('token_type', 'character')
            ->where('source', EsiTokenSource::EveSso->value)
            ->update([
                'source' => EsiTokenSource::AllianceAuth->value,
            ]);
    }
}
