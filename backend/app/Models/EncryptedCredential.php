<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Envelope-encrypted secret for one mail account. Read and written only through
 * App\Security\CredentialVault; never serialised to responses.
 *
 * @property string $email_account_id
 * @property string $kind
 * @property string $ciphertext
 * @property string $nonce
 * @property string $wrapped_dek
 * @property int $kek_version
 */
class EncryptedCredential extends Model
{
    protected $primaryKey = 'email_account_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['ciphertext', 'nonce', 'wrapped_dek'];

    protected function casts(): array
    {
        return ['kek_version' => 'integer'];
    }
}
