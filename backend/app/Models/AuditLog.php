<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only audit record. Rows are written exclusively by App\Services\Audit\AuditLogger.
 *
 * @property int $id
 * @property Carbon $occurred_at
 * @property string|null $actor_user_id
 * @property string $actor_type
 * @property string $action
 * @property string|null $target_type
 * @property string|null $target_id
 * @property string $result
 * @property string|null $ip
 * @property string|null $user_agent
 * @property array<string, mixed>|null $context
 * @property string $prev_hash
 * @property string $hash
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'context' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit logs are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit logs are append-only.'));
    }

    public function ipAddress(): ?string
    {
        return $this->ip === null ? null : (inet_ntop($this->ip) ?: null);
    }
}
