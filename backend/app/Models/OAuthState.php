<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One OAuth authorisation attempt. Only hashes of the state and completion code are
 * stored; the PKCE verifier and pending tokens are encrypted.
 *
 * @property string $state_hash
 * @property string $user_id
 * @property string|null $session_family_id
 * @property string $provider
 * @property string $pkce_verifier_enc
 * @property string|null $completion_hash
 * @property array<string, mixed>|null $pending_payload_enc
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $completed_at
 */
class OAuthState extends Model
{
    protected $table = 'oauth_states';

    protected $primaryKey = 'state_hash';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'pkce_verifier_enc' => 'encrypted',
            'pending_payload_enc' => 'encrypted:array',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
