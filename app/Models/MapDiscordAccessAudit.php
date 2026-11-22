<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of a Discord access grant or revoke.
 *
 * @property int $id
 * @property int|null $map_id
 * @property string $guild_id
 * @property int|null $user_id
 * @property string $discord_user_id
 * @property string $action
 * @property string $reason
 */
final class MapDiscordAccessAudit extends Model
{
    public const string Grant = 'grant';

    public const string Revoke = 'revoke';

    public const string Permission = 'permission';

    public const string Nickname = 'nickname';

    public $timestamps = false;

    protected $table = 'map_discord_access_audits';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
