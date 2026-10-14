<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $provider_profile_id
 * @property string $email_address
 * @property string|null $display_name
 * @property string $status
 * @property string|null $last_error_code
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $next_sync_at
 * @property int $backoff_attempts
 * @property string|null $signature_html
 * @property array<string, bool>|null $capabilities
 * @property string|null $gmail_history_id
 * @property-read ProviderProfile $providerProfile
 * @property-read EncryptedCredential|null $credential
 */
#[Fillable(['display_name', 'signature_html'])]
class EmailAccount extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SYNCING = 'syncing';

    public const STATUS_AUTH_FAILED = 'auth_failed';

    public const STATUS_UNREACHABLE = 'provider_unreachable';

    public const STATUS_THROTTLED = 'throttled';

    public const STATUS_DISABLED = 'disabled';

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'last_synced_at' => 'datetime',
            'next_sync_at' => 'datetime',
            'backoff_attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ProviderProfile, $this> */
    public function providerProfile(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class);
    }

    /** @return HasOne<EncryptedCredential, $this> */
    public function credential(): HasOne
    {
        return $this->hasOne(EncryptedCredential::class);
    }

    /** @return HasMany<Folder, $this> */
    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }
}
