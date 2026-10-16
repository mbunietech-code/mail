<?php

namespace App\Mailbox;

use App\Mailbox\Contracts\MailProvider;
use App\Mailbox\Fake\FakeProvider;
use App\Mailbox\Gmail\GmailProvider;
use App\Mailbox\Gmail\GoogleTokenManager;
use App\Mailbox\ImapSmtp\ImapSmtpProvider;
use App\Mailbox\ImapSmtp\ImapSmtpSettings;
use App\Models\EmailAccount;
use App\Models\ProviderProfile;
use App\Security\CredentialVault;
use App\Security\Secret;
use LogicException;

/**
 * The single place where provider adapters are constructed. Tests swap in a fake via
 * ProviderFactory::fake(); the swap is refused outside the testing environment.
 */
class ProviderFactory
{
    private static ?MailProvider $fake = null;

    private static ?string $fakePassword = null;

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly HostGuard $guard,
        private readonly GoogleTokenManager $googleTokens,
    ) {}

    /** With $password, credential checks accept only that password (others fail with AUTH_FAILED). */
    public static function fake(?MailProvider $provider, ?string $password = null): void
    {
        if ($provider !== null && ! app()->environment('testing')) {
            throw new LogicException('Fake providers are only available in tests.');
        }
        self::$fake = $provider;
        self::$fakePassword = $provider !== null ? $password : null;
    }

    public function forAccount(EmailAccount $account): MailProvider
    {
        if (self::$fake !== null) {
            return self::$fake;
        }

        $profile = $account->providerProfile;
        $credential = $account->credential ?? throw ProviderError::of(ProviderErrorCode::AuthFailed, 'No stored credential.');

        return match ($profile->driver) {
            ProviderProfile::DRIVER_IMAP_SMTP => $this->imap($profile, $account->email_address, $this->vault->open($credential)['password']),
            ProviderProfile::DRIVER_GMAIL => new GmailProvider($account, $this->googleTokens),
            default => throw ProviderError::of(ProviderErrorCode::NotSupported, 'Unknown provider driver.'),
        };
    }

    /** For verifying a credential before an account exists. */
    public function forImapCredentials(ProviderProfile $profile, string $email, Secret $password): MailProvider
    {
        if (self::$fake !== null && self::$fakePassword !== null && ! hash_equals(self::$fakePassword, $password->reveal())) {
            return (new FakeProvider)->failOn('verify', ProviderErrorCode::AuthFailed);
        }

        return self::$fake ?? $this->imap($profile, $email, $password);
    }

    private function imap(ProviderProfile $profile, string $email, mixed $password): MailProvider
    {
        if (! $password instanceof Secret) {
            throw ProviderError::of(ProviderErrorCode::AuthFailed, 'Malformed stored credential.');
        }

        return new ImapSmtpProvider(new ImapSmtpSettings(
            imapHost: (string) $profile->imap_host,
            imapPort: (int) $profile->imap_port,
            imapEncryption: (string) $profile->imap_encryption,
            smtpHost: (string) $profile->smtp_host,
            smtpPort: (int) $profile->smtp_port,
            smtpEncryption: (string) $profile->smtp_encryption,
            username: $email,
            password: $password,
            timeout: (int) config('mbuniemail.providers.timeout_seconds'),
        ), $this->guard);
    }
}
