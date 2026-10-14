<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Idempotent send queue entry (SDD §8.2). The composed payload is encrypted and
 * deleted after delivery.
 *
 * @property string $id
 * @property string $user_id
 * @property string $email_account_id
 * @property string $idempotency_key
 * @property string|null $request_hash
 * @property string $kind
 * @property string $status
 * @property string|null $message_id_header
 * @property array<string, mixed>|null $payload_enc
 * @property int $recipient_count
 * @property int $attempts
 * @property string|null $last_error_code
 * @property Carbon|null $claimed_at
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $sent_at
 * @property string|null $in_reply_to_metadata_id
 * @property string|null $forward_of_metadata_id
 * @property-read EmailAccount $emailAccount
 */
class OutboxMessage extends Model
{
    use HasUlids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN = 'unknown';

    protected $guarded = ['*'];

    protected $hidden = ['payload_enc', 'request_hash'];

    protected function casts(): array
    {
        return [
            'payload_enc' => 'encrypted:array',
            'recipient_count' => 'integer',
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EmailAccount, $this> */
    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }

    /** @return HasMany<OutboxAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(OutboxAttachment::class);
    }
}
