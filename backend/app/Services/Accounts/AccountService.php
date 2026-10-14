<?php

namespace App\Services\Accounts;

use App\Exceptions\ApiException;
use App\Mailbox\Contracts\MailProvider;
use App\Mailbox\Data\FolderInfo;
use App\Mailbox\Gmail\GoogleTokenManager;
use App\Mailbox\ProviderError;
use App\Mailbox\ProviderErrorResponder;
use App\Mailbox\ProviderFactory;
use App\Models\EmailAccount;
use App\Models\EncryptedCredential;
use App\Models\Folder;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Security\CredentialVault;
use App\Security\Secret;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Linking, re-verifying and unlinking mailboxes (SRS FR-ACCT-001..004).
 */
class AccountService
{
    public function __construct(
        private readonly ProviderFactory $providers,
        private readonly CredentialVault $vault,
        private readonly AuditLogger $audit,
    ) {}

    public function linkImapAccount(User $user, string $email, Secret $password, ?string $displayName): EmailAccount
    {
        $email = strtolower(trim($email));
        $org = $user->organization;

        if (! $org->allowsEmail($email)) {
            throw new ApiException('DOMAIN_NOT_ALLOWED', 422, 'Only company mailboxes can be linked.');
        }
        $profile = ProviderProfile::forEmailDomain($email)
            ?? throw new ApiException('PROVIDER_NOT_CONFIGURED', 422, 'No mail server is configured for this domain. Contact IT.');

        if ($user->emailAccounts()->where('email_address', $email)->exists()) {
            throw new ApiException('ACCOUNT_ALREADY_LINKED', 409, 'This mailbox is already linked.');
        }
        if ($user->emailAccounts()->count() >= (int) config('mbuniemail.accounts.max_per_user')) {
            throw new ApiException('ACCOUNT_LIMIT_REACHED', 422, 'You have reached the maximum number of linked mailboxes.');
        }

        $this->consumeLinkAttempt($user);

        $provider = $this->providers->forImapCredentials($profile, $email, $password);
        $folders = $this->verifyOrFail($provider, $user, $email);

        $account = DB::transaction(function () use ($user, $profile, $email, $displayName, $password, $provider, $folders) {
            $account = new EmailAccount(['display_name' => $displayName]);
            $account->forceFill([
                'user_id' => $user->id,
                'provider_profile_id' => $profile->id,
                'email_address' => $email,
                'status' => EmailAccount::STATUS_ACTIVE,
                'capabilities' => $provider->capabilities()->toArray(),
                'next_sync_at' => now(),
            ])->save();

            $this->storeCredential($account, 'password', ['password' => $password->reveal()]);
            $this->syncFolders($account, $folders);

            return $account;
        });
        $provider->disconnect();

        $this->audit->record('account.linked', 'success', $user, 'email_account', $account->id, ['provider' => $profile->key, 'folders' => count($folders)]);

        return $account;
    }

    public function updatePassword(EmailAccount $account, Secret $password): EmailAccount
    {
        $user = $account->user;
        $this->consumeLinkAttempt($user);

        $provider = $this->providers->forImapCredentials($account->providerProfile, $account->email_address, $password);
        $folders = $this->verifyOrFail($provider, $user, $account->email_address);

        DB::transaction(function () use ($account, $password, $folders) {
            $this->storeCredential($account, 'password', ['password' => $password->reveal()]);
            $account->forceFill(['status' => EmailAccount::STATUS_ACTIVE, 'last_error_code' => null, 'backoff_attempts' => 0, 'next_sync_at' => now()])->save();
            $this->syncFolders($account, $folders);
        });
        $provider->disconnect();

        $this->audit->record('account.credential.updated', 'success', $user, 'email_account', $account->id);

        return $account->refresh();
    }

    /** Deletes the credential and all cached data immediately (cascade). */
    public function unlink(EmailAccount $account, ?User $actor = null): void
    {
        if ($account->providerProfile->driver === ProviderProfile::DRIVER_GMAIL) {
            app(GoogleTokenManager::class)->revoke($account);
        }

        $id = $account->id;
        $account->delete();
        $this->audit->record('account.unlinked', 'success', $actor ?? $account->user, 'email_account', $id);
    }

    /**
     * @param  array<string, mixed>  $secret
     */
    public function storeCredential(EmailAccount $account, string $kind, #[\SensitiveParameter] array $secret): void
    {
        $sealed = $this->vault->seal($account->id, $secret);
        EncryptedCredential::query()->updateOrInsert(
            ['email_account_id' => $account->id],
            ['kind' => $kind, ...$sealed, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    /**
     * Upserts folders from the provider and removes ones that no longer exist.
     *
     * @param  list<FolderInfo>  $folders
     */
    public function syncFolders(EmailAccount $account, array $folders): void
    {
        $seen = [];
        foreach ($folders as $info) {
            $seen[] = $info->path;
            $folder = Folder::query()->where('email_account_id', $account->id)->where('remote_name', $info->path)->first() ?? new Folder;
            $folder->forceFill([
                'email_account_id' => $account->id,
                'remote_name' => $info->path,
                'delimiter' => $info->delimiter,
                'role' => in_array($info->role, Folder::ROLES, true) ? $info->role : 'custom',
                'selectable' => $info->selectable,
            ])->save();
        }

        Folder::query()->where('email_account_id', $account->id)->whereNotIn('remote_name', $seen)->delete();
    }

    /**
     * @return list<FolderInfo>
     */
    private function verifyOrFail(MailProvider $provider, User $user, string $email): array
    {
        try {
            $provider->verify();

            return $provider->listFolders();
        } catch (ProviderError $e) {
            $provider->disconnect();
            report($e);
            $this->audit->record('account.verification_failed', 'failure', $user, 'email_account', null, ['code' => $e->errorCode->value, 'domain' => substr(strrchr($email, '@') ?: '', 1)]);

            throw ProviderErrorResponder::toApi($e);
        }
    }

    /** Limits credential checks so the API cannot be used to brute-force mailbox passwords. */
    private function consumeLinkAttempt(User $user): void
    {
        $key = 'account-link:'.$user->id;
        $max = (int) config('mbuniemail.accounts.link_attempts_per_10_minutes');

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ApiException::tooManyAttempts(RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 600);
    }
}
