<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Administrator-managed server settings. Users never supply hostnames (anti-SSRF).
 *
 * @property string $id
 * @property string $key
 * @property string $label
 * @property string $driver
 * @property string|null $imap_host
 * @property int|null $imap_port
 * @property string|null $imap_encryption
 * @property string|null $smtp_host
 * @property int|null $smtp_port
 * @property string|null $smtp_encryption
 * @property bool $saves_sent_automatically
 * @property int $max_connections_per_mailbox
 * @property list<string> $domains
 * @property bool $enabled
 */
class ProviderProfile extends Model
{
    use HasUlids;

    public const DRIVER_IMAP_SMTP = 'imap_smtp';

    public const DRIVER_GMAIL = 'gmail';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'saves_sent_automatically' => 'boolean',
            'enabled' => 'boolean',
            'imap_port' => 'integer',
            'smtp_port' => 'integer',
            'max_connections_per_mailbox' => 'integer',
        ];
    }

    public static function forEmailDomain(string $email): ?self
    {
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));

        return self::query()
            ->where('driver', self::DRIVER_IMAP_SMTP)
            ->where('enabled', true)
            ->get()
            ->first(fn (self $p) => in_array($domain, array_map('strtolower', $p->domains), true));
    }
}
