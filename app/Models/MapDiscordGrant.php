<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $map_id
 * @property string $guild_id
 * @property int $user_id
 * @property string $discord_user_id
 * @property-read Map $map
 * @property-read User $user
 */
final class MapDiscordGrant extends Model
{
    protected $table = 'map_discord_grants';

    /** @return BelongsTo<Map, $this> */
    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
