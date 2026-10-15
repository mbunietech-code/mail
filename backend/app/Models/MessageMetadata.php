<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Cached envelope of one message (SDD ADR-002). Subject and snippet are encrypted at
 * rest; bodies and attachment bytes are never stored.
 *
 * @property string $id
 * @property string $email_account_id
 * @property string $folder_id
 * @property int $uidvalidity
 * @property int $uid
 * @property string|null $provider_message_id
 * @property string|null $provider_thread_id
 * @property string|null $message_id_header
 * @property string $thread_key
 * @property string|null $in_reply_to
 * @property list<string>|null $references_json
 * @property string|null $from_name
 * @property string|null $from_email
 * @property list<array{email: string, name: string|null}>|null $to_json
 * @property list<array{email: string, name: string|null}>|null $cc_json
 * @property list<array{email: string, name: string|null}>|null $reply_to_json
 * @property string|null $subject_enc
 * @property string|null $snippet_enc
 * @property Carbon|null $sent_at
 * @property Carbon $internal_date
 * @property int $size_bytes
 * @property bool $is_seen
 * @property bool $is_flagged
 * @property bool $is_answered
 * @property bool $is_draft
 * @property bool $has_attachments
 * @property array<string, string>|null $auth_results
 * @property-read Folder $folder
 * @property-read EmailAccount $emailAccount
 */
class MessageMetadata extends Model
{
    use HasUlids;

    protected $table = 'message_metadata';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'uidvalidity' => 'integer',
            'uid' => 'integer',
            'references_json' => 'array',
            'to_json' => 'array',
            'cc_json' => 'array',
            'reply_to_json' => 'array',
            'subject_enc' => 'encrypted',
            'snippet_enc' => 'encrypted',
            'sent_at' => 'datetime',
            'internal_date' => 'datetime',
            'size_bytes' => 'integer',
            'is_seen' => 'boolean',
            'is_flagged' => 'boolean',
            'is_answered' => 'boolean',
            'is_draft' => 'boolean',
            'has_attachments' => 'boolean',
            'auth_results' => 'array',
        ];
    }

    /** @return BelongsTo<Folder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    /** @return BelongsTo<EmailAccount, $this> */
    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }

    /** @return HasMany<AttachmentMetadata, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(AttachmentMetadata::class);
    }

    /**
     * Messages belonging to the given user's accounts — the only entry point for
     * content queries (DATABASE_DESIGN §4).
     *
     * @param  Builder<MessageMetadata>  $query
     */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->whereIn('email_account_id', $user->usableEmailAccounts()->select('id'));
    }
}
