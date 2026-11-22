<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $map_id
 * @property string|null $guild_id
 * @property string|null $guild_name
 * @property string $status
 * @property string $permission
 * @property CarbonImmutable|null $pending_expires_at
 * @property string|null $pending_state_hash
 * @property int $unmatched_count
 * @property-read Map $map
 */
final class MapDiscordServer extends Model
{
    public const string Pending = 'pending';

    public const string Active = 'active';

    public const string Removed = 'removed';

    protected $table = 'map_discord_servers';

    /** @return BelongsTo<Map, $this> */
    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::Pending
            && $this->pending_expires_at instanceof CarbonImmutable
            && $this->pending_expires_at->isFuture();
    }

    public function derivedPermission(): ?Permission
    {
        return match ($this->permission) {
            Permission::Viewer->value => Permission::Viewer,
            Permission::Member->value => Permission::Member,
            default => null,
        };
    }

    public function accessState(): string
    {
        if ($this->status === self::Pending) {
            return $this->isPending() ? 'pending' : 'expired';
        }

        return $this->status;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pending_expires_at' => 'immutable_datetime',
            'unmatched_count' => 'integer',
        ];
    }
}
