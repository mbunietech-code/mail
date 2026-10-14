<?php

namespace App\Security;

use App\Models\EncryptedCredential;
use RuntimeException;

/**
 * Envelope encryption for mailbox passwords and OAuth tokens (SDD §9, risk R-12).
 *
 *  - each credential gets a fresh random data key (DEK);
 *  - the secret is sealed with XChaCha20-Poly1305 under the DEK, with the account id as
 *    associated data, so a ciphertext copied onto another account will not decrypt;
 *  - the DEK is sealed under the current key-encryption key (KEK) from the KeyRing,
 *    which lives outside the database and `.env`.
 * Rotating the KEK only requires re-wrapping DEKs, not re-encrypting secrets.
 */
class CredentialVault
{
    private const AAD_SECRET = 'mbuniemail:credential:v1:';

    private const AAD_DEK = 'mbuniemail:dek:v1:';

    public function __construct(private readonly KeyRing $keys) {}

    /**
     * @param  array<string, mixed>  $secret
     * @return array{ciphertext: string, nonce: string, wrapped_dek: string, kek_version: int}
     */
    public function seal(string $accountId, #[\SensitiveParameter] array $secret): array
    {
        $dek = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $plain = json_encode($secret, JSON_THROW_ON_ERROR);

        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, self::AAD_SECRET.$accountId, $nonce, $dek);
            $version = $this->keys->currentVersion();

            return [
                'ciphertext' => $ciphertext,
                'nonce' => $nonce,
                'wrapped_dek' => $this->wrap($dek, $accountId, $version),
                'kek_version' => $version,
            ];
        } finally {
            sodium_memzero($plain);
            sodium_memzero($dek);
        }
    }

    /**
     * @return array<string, Secret|mixed> String values are wrapped in Secret.
     */
    public function open(EncryptedCredential $credential): array
    {
        $accountId = $credential->email_account_id;
        $dek = $this->unwrap($credential->wrapped_dek, $accountId, $credential->kek_version);

        try {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $credential->ciphertext,
                self::AAD_SECRET.$accountId,
                $credential->nonce,
                $dek,
            );
        } finally {
            sodium_memzero($dek);
        }

        if ($plain === false) {
            throw new RuntimeException('Credential failed integrity check.');
        }

        $data = json_decode($plain, true, flags: JSON_THROW_ON_ERROR);
        sodium_memzero($plain);

        return array_map(fn ($v) => is_string($v) ? new Secret($v) : $v, $data);
    }

    /** Re-wraps the DEK under the current KEK. Returns false if already current. */
    public function rewrap(EncryptedCredential $credential): bool
    {
        $current = $this->keys->currentVersion();
        if ($credential->kek_version === $current) {
            return false;
        }

        $dek = $this->unwrap($credential->wrapped_dek, $credential->email_account_id, $credential->kek_version);
        try {
            $credential->forceFill([
                'wrapped_dek' => $this->wrap($dek, $credential->email_account_id, $current),
                'kek_version' => $current,
            ])->save();
        } finally {
            sodium_memzero($dek);
        }

        return true;
    }

    private function wrap(string $dek, string $accountId, int $version): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return $nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($dek, self::AAD_DEK.$accountId.'|'.$version, $nonce, $this->keys->key($version));
    }

    private function unwrap(string $wrapped, string $accountId, int $version): string
    {
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $dek = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($wrapped, $nonceLength),
            self::AAD_DEK.$accountId.'|'.$version,
            substr($wrapped, 0, $nonceLength),
            $this->keys->key($version),
        );

        if ($dek === false) {
            throw new RuntimeException('Credential key failed integrity check.');
        }

        return $dek;
    }
}
