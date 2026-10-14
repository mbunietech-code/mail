<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $family_id
 * @property string|null $device_id
 * @property string|null $ip_prefix
 * @property Carbon|null $rotated_at
 * @property Carbon|null $revoked_at
 * @property Carbon $expires_at
 * @property Carbon $idle_expires_at
 * @property Carbon $updated_at
 */
#[Fillable(['user_id', 'family_id', 'token_hash', 'device_id', 'ip_prefix', 'user_agent', 'expires_at', 'idle_expires_at'])]
#[Hidden(['token_hash'])]
class RefreshSession extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'rotated_at' => 'datetime',
            'revoked_at' => 'datetime',
            'expires_at' => 'datetime',
            'idle_expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Sessions whose refresh token can still be exchanged.
     *
     * @param  Builder<RefreshSession>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('rotated_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->where('idle_expires_at', '>', now());
    }

    public function isUsable(): bool
    {
        return $this->rotated_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture()
            && $this->idle_expires_at->isFuture();
    }
}
