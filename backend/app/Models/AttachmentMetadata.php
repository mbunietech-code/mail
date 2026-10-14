<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $message_metadata_id
 * @property string $part_id
 * @property string $filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $content_id
 * @property bool $is_inline
 * @property-read MessageMetadata $message
 */
class AttachmentMetadata extends Model
{
    use HasUlids;

    protected $table = 'attachment_metadata';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'is_inline' => 'boolean'];
    }

    /** @return BelongsTo<MessageMetadata, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(MessageMetadata::class, 'message_metadata_id');
    }
}
