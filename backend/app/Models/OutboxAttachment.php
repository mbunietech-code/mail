<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Uploaded attachment staged on the private disk under a random name.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $outbox_message_id
 * @property string $storage_path
 * @property string $filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $scan_status
 */
class OutboxAttachment extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected $hidden = ['storage_path'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }
}
