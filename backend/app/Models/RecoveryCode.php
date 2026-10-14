<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code_hash
 * @property Carbon|null $used_at
 */
#[Fillable(['user_id', 'code_hash'])]
#[Hidden(['code_hash'])]
class RecoveryCode extends Model
{
    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }
}
