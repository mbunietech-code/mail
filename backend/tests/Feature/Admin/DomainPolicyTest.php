<?php

namespace Tests\Feature\Admin;

use App\Jobs\SendMessageJob;
use App\Jobs\SyncAccountJob;
use App\Mailbox\MimeComposer;
use App\Mailbox\ProviderErrorCode;
use App\Mailbox\ProviderFactory;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\OutboxMessage;
use App\Models\ProviderProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\DomainService;
use App\Services\Audit\AuditLogger;
use App\Support\CompanyDomains;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\MailFixtures;
use Tests\TestCase;

/**
 * Company domains are the only addresses that may use MbunieMail. Administrators
 * add and remove them; removal takes effect everywhere at once.
 */
class DomainPolicyTest extends TestCase
{
    use MailFixtures;

    private function hostinger(): ProviderProfile
    {
        return ProviderProfile::query()->where('key', 'hostinger')->firstOrFail();
    }

    private function signedInAdmin(): User
    {
        $admin = $this->makeUser([], Role::SUPER_ADMIN);
        $this->enableMfa($admin);
        $this->actingAs($admin, 'web')->withSession(['admin_mfa_user' => $admin->id, 'admin_reauth_at' => now()->getTimestamp()]);

        return $admin;
    }

    public function test_admin_adds_domains_with_a_mail_server_and_they_are_audited(): void
    {
        $this->signedInAdmin();

        $this->get('/admin/domains')->assertOk()->assertSee('mt.co.tz');
        $this->post('/admin/domains', ['domain' => '  @MbunieHub.com ', 'server' => $this->hostinger()->id])->assertSessionHas('status');
        $this->post('/admin/domains', ['domain' => 'mbunir.tech', 'server' => $this->hostinger()->id])->assertSessionHas('status');

        $this->assertSame(['mt.co.tz', 'mbuniehub.com', 'mbunir.tech'], CompanyDomains::all());
        $this->assertContains('mbuniehub.com', $this->hostinger()->fresh()->domains);
        $this->assertSame('mbuniehub.com', AuditLog::query()->where('action', 'admin.domain.added')->firstOrFail()->context['domain']);
        $this->get('/admin/domains')->assertSee('mbunir.tech');

        // Invitations and mailbox linking now accept the new domains.
        $this->post('/admin/invitations', ['email' => 'new.person@mbunir.tech', 'role' => 'employee'])->assertSessionHas('status');
        $this->assertTrue(Invitation::query()->where('email', 'new.person@mbunir.tech')->exists());
    }

    public function test_invalid_duplicate_and_unreviewed_changes_are_refused(): void
    {
        $this->signedInAdmin();

        foreach (['not a domain', 'http://evil.com', 'localhost', 'a..b.com', '-x.com', str_repeat('a', 64).'.com'] as $bad) {
            $this->post('/admin/domains', ['domain' => $bad])->assertSessionHasErrors('domain');
        }
        $this->post('/admin/domains', ['domain' => 'MT.CO.TZ'])->assertSessionHasErrors('domain');
        $this->assertSame(['mt.co.tz'], CompanyDomains::all());

        // Writes need a fresh re-authentication.
        $this->withSession(['admin_reauth_at' => now()->subHour()->getTimestamp()])
            ->post('/admin/domains', ['domain' => 'mbuniehub.com'])->assertRedirect('/admin/reauth');
        $this->assertSame(['mt.co.tz'], CompanyDomains::all());
    }

    public function test_removal_guards_prevent_lockout(): void
    {
        $admin = $this->signedInAdmin();

        $this->post('/admin/domains/mt.co.tz/remove', ['confirm' => '1'])->assertSessionHas('error'); // the only domain
        app(DomainService::class)->add('mbuniehub.com', $this->hostinger(), $admin);

        $this->post('/admin/domains/mt.co.tz/remove', ['confirm' => '1'])->assertSessionHas('error'); // the admin's own domain
        $this->post('/admin/domains/mbuniehub.com/remove')->assertSessionHasErrors('confirm');
        $this->assertSame(['mt.co.tz', 'mbuniehub.com'], CompanyDomains::all());

        // No super administrator would be left on the remaining domains.
        $this->makeUser(['email' => 'boss@mbuniehub.com']);
        $this->expectExceptionMessage('no active super administrator would remain');
        app(DomainService::class)->remove('mt.co.tz', null);
    }

    public function test_sign_in_is_limited_to_company_domains(): void
    {
        $outsider = $this->makeUser(['email' => 'someone@gmail.com']);

        foreach ([$outsider->email, 'nobody@gmail.com'] as $email) {
            $this->freshRequest()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD])
                ->assertForbidden()->assertJsonPath('code', 'DOMAIN_NOT_ALLOWED');
        }
        $this->getJson('/api/v1/auth/domains')->assertOk()->assertJsonPath('domains', ['mt.co.tz']);
        $this->post('/admin/login', ['email' => $outsider->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
    }

    public function test_removing_a_domain_locks_out_its_people_and_mailboxes_everywhere(): void
    {
        $this->useFakeProvider();
        app(DomainService::class)->add('mbuniehub.com', $this->hostinger(), null);
        $user = $this->makeUser(['email' => 'amina@mbuniehub.com']);
        $account = $this->linkAccount($user, 'amina@mbuniehub.com');
        $tokens = $this->tokensFor($user);
        $auth = $this->bearer($tokens['access_token']);

        // A queued message waiting for retry when the domain disappears.
        $this->fake->failOn('submit', ProviderErrorCode::Unreachable);
        $outboxId = $this->freshRequest()->postJson('/api/v1/outbox/send', ['account_id' => $account->id, 'to' => ['x@example.org'], 'subject' => 's', 'html' => '<p>t</p>'],
            [...$auth, 'Idempotency-Key' => (string) Str::ulid()])->json('id');
        unset($this->fake->failures['submit']);
        OutboxMessage::query()->whereKey($outboxId)->update(['status' => 'queued', 'next_attempt_at' => null]);

        app(DomainService::class)->remove('mbuniehub.com', null);

        $this->freshRequest()->getJson('/api/v1/me', $auth)->assertUnauthorized();
        $this->freshRequest()->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertUnauthorized();
        $this->freshRequest()->login($user)->assertForbidden()->assertJsonPath('code', 'DOMAIN_NOT_ALLOWED');

        (new SendMessageJob($outboxId))->handle(app(ProviderFactory::class), app(MimeComposer::class), app(AuditLogger::class));
        $this->assertSame(['failed', 'DOMAIN_NOT_ALLOWED'], [OutboxMessage::query()->findOrFail($outboxId)->status, OutboxMessage::query()->findOrFail($outboxId)->last_error_code]);
        $this->assertCount(0, $this->fake->submitted);

        Queue::fake();
        $account->forceFill(['next_sync_at' => now()->subMinute()])->save();
        $this->artisan('mbuniemail:sync-due')->assertSuccessful();
        Queue::assertNotPushed(SyncAccountJob::class);

        // Adding the domain back restores access.
        app(DomainService::class)->add('mbuniehub.com', $this->hostinger(), null);
        $this->freshRequest()->login($user)->assertOk();
    }

    public function test_mailboxes_on_a_removed_domain_are_listed_but_unreadable(): void
    {
        $this->useFakeProvider();
        app(DomainService::class)->add('mbuniehub.com', $this->hostinger(), null);
        $user = $this->makeUser();
        $other = $this->linkAccount($user, 'shared@mbuniehub.com');
        $auth = $this->bearer($this->tokensFor($user)['access_token']);

        app(DomainService::class)->remove('mbuniehub.com', null);

        $this->freshRequest()->getJson('/api/v1/accounts', $auth)->assertOk()
            ->assertJsonPath('data.0.status', 'domain_not_allowed');
        $this->freshRequest()->postJson("/api/v1/accounts/{$other->id}/sync", [], $auth)->assertNotFound();
        $this->freshRequest()->getJson("/api/v1/accounts/{$other->id}/search?q=x", $auth)->assertNotFound();
        $this->assertNotContains($other->id, $user->usableEmailAccounts()->pluck('id')->all());
    }

    public function test_domains_command_is_a_recovery_path(): void
    {
        $this->artisan('mbuniemail:domains', ['action' => 'add', 'domain' => 'mbunir.tech'])->assertSuccessful();
        $this->assertContains('mbunir.tech', CompanyDomains::all());
        $this->assertContains('mbunir.tech', $this->hostinger()->fresh()->domains);
        $this->artisan('mbuniemail:domains', ['action' => 'add', 'domain' => 'bad domain'])->assertFailed();
        $this->artisan('mbuniemail:domains')->assertSuccessful()->expectsOutputToContain('mbunir.tech');
    }
}
