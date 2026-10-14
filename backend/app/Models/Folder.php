<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cached folder (IMAP mailbox / Gmail label) of an email account.
 *
 * @property string $id
 * @property string $email_account_id
 * @property string $remote_name
 * @property string|null $delimiter
 * @property string $role
 * @property bool $selectable
 * @property int $unread_count
 * @property int $total_count
 */
class Folder extends Model
{
    use HasUlids;

    public const ROLES = ['inbox', 'sent', 'drafts', 'archive', 'trash', 'spam', 'custom'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['selectable' => 'boolean', 'unread_count' => 'integer', 'total_count' => 'integer'];
    }

    /** @return BelongsTo<EmailAccount, $this> */
    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }
}
