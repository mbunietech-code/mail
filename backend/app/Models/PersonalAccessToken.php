<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Access token linked to the refresh session that issued it, so revoking a
 * session (or a whole family) also kills its outstanding access tokens.
 *
 * @property string|null $refresh_session_id
 */
class PersonalAccessToken extends SanctumToken
{
    /** @return BelongsTo<RefreshSession, $this> */
    public function refreshSession(): BelongsTo
    {
        return $this->belongsTo(RefreshSession::class);
    }
}
