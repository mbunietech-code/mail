<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Per-folder synchronisation checkpoint (survives restarts; FR-SYNC-006).
 *
 * @property string $folder_id
 * @property int|null $uidvalidity
 * @property int $last_uid
 * @property int|null $highest_modseq
 * @property string|null $history_id
 * @property Carbon|null $last_full_sync_at
 * @property Carbon|null $last_incremental_at
 * @property string|null $last_error_code
 * @property int $attempts
 */
class SyncState extends Model
{
    protected $primaryKey = 'folder_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'uidvalidity' => 'integer',
            'last_uid' => 'integer',
            'highest_modseq' => 'integer',
            'last_full_sync_at' => 'datetime',
            'last_incremental_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
