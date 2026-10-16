<?php

namespace App\Services\Auth;

use App\Mailbox\ProviderError;
use App\Mailbox\ProviderErrorCode;
use App\Mailbox\ProviderErrorResponder;
use App\Mailbox\ProviderFactory;
use App\Models\EmailAccount;
use App\Models\Organization;
use App\Models\ProviderProfile;
use App\Models\Role;
use App\Models\User;
use App\Security\Secret;
use App\Services\Accounts\AccountService;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Employees sign in with their company mailbox (Hostinger) address and password: the
 * provider is the identity check, and the mailbox it accepts becomes the user's linked
 * account. First sign-in provisions the MbunieMail user.
 */
class MailboxSignIn
{
    public function __construct(
        private readonly ProviderFactory $providers,
        private readonly AccountService $accounts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Null when the provider rejects the password (or no mail server serves the domain),
     * so the caller can answer with the same generic error either way. Other provider
     * failures (TLS, timeout, throttling) surface as actionable API errors.
     */
    public function verify(string $email, Secret $password): ?VerifiedMailbox
    {
        $profile = ProviderProfile::forEmailDomain($email);
        if ($profile === null) {
            return null;
        }

        $provider = $this->providers->forImapCredentials($profile, $email, $password);
        try {
            $provider->verify();
            $folders = $provider->listFolders();
        } catch (ProviderError $e) {
            $provider->disconnect();
            if ($e->errorCode === ProviderErrorCode::AuthFailed) {
                return null;
            }
            report($e);
            $this->audit->record('auth.login.provider_error', 'failure', context: ['code' => $e->errorCode->value, 'provider' => $profile->key]);

            throw ProviderErrorResponder::toApi($e);
        }

        return new VerifiedMailbox($email, $profile, $provider, $folders);
    }

    /** The existing user for this mailbox, or a new employee in the organisation that owns its domain. */
    public function userFor(string $email): User
    {
        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($email) {
            $org = Organization::query()->get()->first(fn (Organization $o) => $o->allowsEmail($email))
                ?? Organization::query()->firstOrFail();

            // The mailbox password is the sign-in secret; the local password is unusable.
            $user = User::query()->create([
                'organization_id' => $org->id,
                'name' => self::nameFrom($email),
                'email' => $email,
                'password' => Str::random(64),
            ]);
            $user->forceFill(['status' => User::STATUS_ACTIVE])->save();
            $user->roles()->attach(Role::query()->where('name', Role::EMPLOYEE)->valueOrFail('id'));

            $this->audit->record('auth.user.provisioned', 'success', $user, 'user', $user->id, ['via' => 'mailbox_sign_in'], 'system');

            return $user;
        });
    }

    /** Links (or refreshes the stored password of) the mailbox the user signed in with. */
    public function link(User $user, VerifiedMailbox $mailbox, Secret $password): EmailAccount
    {
        try {
            return $this->accounts->linkVerified($user, $mailbox, $password);
        } finally {
            $mailbox->provider->disconnect();
        }
    }

    private static function nameFrom(string $email): string
    {
        $local = Str::before($email, '@');

        return Str::title(trim((string) preg_replace('/[._-]+/', ' ', $local))) ?: $email;
    }
}
