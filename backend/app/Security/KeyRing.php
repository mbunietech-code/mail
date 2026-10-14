<?php

namespace App\Security;

use RuntimeException;

/**
 * Key-encryption keys (KEKs) for mailbox credentials, loaded from a file kept outside
 * the application directory, `.env` and database backups (SDD §9). Format:
 *   {"current": 2, "keys": {"1": "<base64 32 bytes>", "2": "<base64 32 bytes>"}}
 * Older versions stay until `mbuniemail:kek-rewrap` has moved every credential off them.
 */
class KeyRing
{
    /** @var array<int, string>|null */
    private ?array $keys = null;

    private int $current = 0;

    public function __construct(private readonly string $path) {}

    public function currentVersion(): int
    {
        $this->load();

        return $this->current;
    }

    public function key(int $version): string
    {
        $this->load();

        return $this->keys[$version] ?? throw new RuntimeException("Credential KEK version {$version} is not available.");
    }

    /** @return list<int> */
    public function versions(): array
    {
        $this->load();

        return array_keys($this->keys ?? []);
    }

    /**
     * Creates the key file, or adds a new version and makes it current.
     */
    public static function rotate(string $path): int
    {
        $data = ['current' => 0, 'keys' => []];
        if (is_file($path)) {
            $data = self::readFile($path);
        }

        $version = $data['keys'] === [] ? 1 : max(array_map('intval', array_keys($data['keys']))) + 1;
        $data['keys'][(string) $version] = base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
        $data['current'] = $version;

        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Cannot create key directory.');
        }

        $tmp = $path.'.tmp';
        $old = umask(0077);
        try {
            if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                throw new RuntimeException('Cannot write key file.');
            }
            chmod($tmp, 0600);
            rename($tmp, $path);
        } finally {
            umask($old);
        }

        return $version;
    }

    private function load(): void
    {
        if ($this->keys !== null) {
            return;
        }

        if ($this->path === '' || ! is_file($this->path)) {
            throw new RuntimeException('Credential key file not found. Set CREDENTIAL_KEK_PATH and run `php artisan mbuniemail:kek-rotate`.');
        }

        if (DIRECTORY_SEPARATOR === '/' && (fileperms($this->path) & 0o077) !== 0) {
            throw new RuntimeException('Credential key file permissions are too open; it must be readable by the owner only (chmod 600).');
        }

        $data = self::readFile($this->path);
        $keys = [];
        foreach ($data['keys'] as $version => $encoded) {
            $raw = base64_decode((string) $encoded, true);
            if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new RuntimeException("Credential KEK version {$version} is malformed.");
            }
            $keys[(int) $version] = $raw;
        }

        if (! isset($keys[(int) $data['current']])) {
            throw new RuntimeException('Current credential KEK version is missing from the key file.');
        }

        $this->keys = $keys;
        $this->current = (int) $data['current'];
    }

    /** @return array{current: int, keys: array<string, string>} */
    private static function readFile(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! isset($data['current'], $data['keys']) || ! is_array($data['keys'])) {
            throw new RuntimeException('Credential key file is malformed.');
        }

        return $data;
    }
}
