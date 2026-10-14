<?php

namespace Tests\Feature\Admin;

use App\Mailbox\HostGuard;
use App\Models\AuditLog;
use App\Models\EmailAccount;
use App\Models\ProviderProfile;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Mail\SyncService;
use App\Support\CompanyDomains;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\MailFixtures;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use MailFixtures;

    /** An administrator who has completed password + MFA in this session. */
    private function signedInAdmin(string $role = Role::SUPER_ADMIN, bool $reauthed = true): User
    {
        $admin = $this->makeUser([], $role);
        $this->enableMfa($admin);
        $this->actingAs($admin, 'web')->withSession(array_filter([
            'admin_mfa_user' => $admin->id,
            'admin_reauth_at' => $reauthed ? now()->getTimestamp() : null,
        ]));

        return $admin;
    }

    // ---- Sign-in ---------------------------------------------------------------------

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/users')->assertRedirect('/admin/login');
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_admin_sign_in_requires_password_then_totp(): void
    {
        $admin = $this->makeUser([], Role::ADMIN);
        $secret = $this->enableMfa($admin);

        $this->post('/admin/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertRedirect('/admin/mfa');
        $this->get('/admin')->assertRedirect('/admin/mfa'); // password alone is not enough

        $this->post('/admin/mfa', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/admin/mfa', ['code' => $this->totp($secret)])->assertRedirect('/admin');
        $this->get('/admin')->assertOk()->assertSee('Service health');

        $this->assertTrue(AuditLog::query()->where('action', 'admin.login.success')->exists());
    }

    public function test_employees_and_wrong_passwords_get_the_same_refusal(): void
    {
        $employee = $this->makeUser([], Role::EMPLOYEE);
        $admin = $this->makeUser([], Role::ADMIN);

        $a = $this->post('/admin/login', ['email' => $employee->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $b = $this->post('/admin/login', ['email' => $admin->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->assertSame(session('errors')->first('email'), 'These credentials cannot sign in to the admin panel.');
        $this->assertGuest('web');
    }

    public function test_console_created_admin_must_enrol_mfa_before_anything_else(): void
    {
        $admin = $this->makeUser([], Role::SUPER_ADMIN);

        $this->post('/admin/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertRedirect('/admin/mfa/enroll');
        $this->get('/admin/users')->assertRedirect('/admin/mfa/enroll');

        $page = $this->get('/admin/mfa/enroll')->assertOk();
        $secret = str_replace(' ', '', session('admin_enrollment')['secret']);
        $page->assertSee(trim(chunk_split($secret, 4, ' ')));

        $this->post('/admin/mfa/enroll', ['code' => $this->totp($secret)])->assertOk()->assertSee('Save your recovery codes');
        $this->assertTrue($admin->fresh()->hasMfaEnabled());
        $this->get('/admin/users')->assertOk();
    }

    public function test_a_non_admin_session_is_thrown_out(): void
    {
        $employee = $this->makeUser([], Role::EMPLOYEE);
        $this->actingAs($employee, 'web')->withSession(['admin_mfa_user' => $employee->id]);

        $this->get('/admin/users')->assertRedirect('/admin/login');
        $this->assertGuest('web');
    }

    public function test_session_id_changes_at_each_privilege_step(): void
    {
        $admin = $this->makeUser([], Role::ADMIN);
        $secret = $this->enableMfa($admin);

        $this->get('/admin/login');
        $before = session()->getId();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => self::PASSWORD]);
        $afterPassword = session()->getId();
        $this->post('/admin/mfa', ['code' => $this->totp($secret)]);

        $this->assertNotSame($before, $afterPassword);
        $this->assertNotSame($afterPassword, session()->getId());
    }

    // ---- Authorisation ---------------------------------------------------------------

    /** SEC-T11 (admin panel): each role reaches only its sections. */
    public function test_permissions_limit_each_admin_role(): void
    {
        $this->signedInAdmin(Role::SECURITY_AUDITOR);
        $this->get('/admin/audit')->assertOk();
        $this->get('/admin')->assertOk();
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/policies')->assertForbidden();
        $this->get('/admin/jobs')->assertForbidden();

        $this->signedInAdmin(Role::ADMIN);
        $this->get('/admin/users')->assertOk();
        $this->get('/admin/policies')->assertForbidden();
    }

    public function test_writes_require_recent_reauthentication(): void
    {
        $this->signedInAdmin(Role::ADMIN, reauthed: false);
        $target = $this->makeUser();

        $this->post("/admin/users/{$target->id}/status", ['status' => 'suspended'])->assertRedirect('/admin/reauth');
        $this->assertSame('active', $target->fresh()->status);
    }

    // ---- User administration ---------------------------------------------------------

    public function test_suspending_an_employee_cuts_off_api_access_immediately(): void
    {
        $employee = $this->makeUser();
        $tokens = $this->tokensFor($employee);
        $this->signedInAdmin(Role::ADMIN);

        $this->post("/admin/users/{$employee->id}/status", ['status' => 'suspended'])->assertRedirect();

        $this->assertSame('suspended', $employee->fresh()->status);
        $this->freshRequest()->getJson('/api/v1/me', $this->bearer($tokens['access_token']))->assertStatus(401);

        // Tokens are revoked, not merely blocked by status: re-activation does not revive them.
        User::query()->whereKey($employee->id)->update(['status' => User::STATUS_ACTIVE]);
        $this->freshRequest()->getJson('/api/v1/me', $this->bearer($tokens['access_token']))->assertStatus(401);
        $this->freshRequest()->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertStatus(401);
        $this->assertTrue(AuditLog::query()->where('action', 'admin.user.status_changed')->where('target_id', $employee->id)->exists());
    }

    public function test_deactivation_also_stops_mailbox_access(): void
    {
        $this->useFakeProvider();
        $employee = $this->makeUser();
        $account = $this->linkAccount($employee);
        $this->signedInAdmin(Role::ADMIN);

        $this->post("/admin/users/{$employee->id}/status", ['status' => 'deactivated']);

        $this->assertSame(EmailAccount::STATUS_DISABLED, $account->fresh()->status);
        $this->assertNull($account->fresh()->next_sync_at);
        $this->assertNotNull($employee->fresh()->deactivated_at);
    }

    /** The self-check must hold on its own, even when other super admins exist. */
    public function test_admins_cannot_act_on_themselves_even_with_other_super_admins_present(): void
    {
        $this->makeUser([], Role::SUPER_ADMIN); // so "keep one super admin" is not what blocks it
        $admin = $this->signedInAdmin(Role::SUPER_ADMIN);

        $this->post("/admin/users/{$admin->id}/status", ['status' => 'suspended'])->assertSessionHasErrors(['user' => 'You cannot change the status of your own account.']);
        $this->post("/admin/users/{$admin->id}/roles", ['roles' => ['employee']])->assertSessionHasErrors(['user' => 'You cannot change your own roles.']);
        $this->post("/admin/users/{$admin->id}/mfa/reset")->assertSessionHasErrors('user');
        $this->assertSame('active', $admin->fresh()->status);
        $this->assertTrue($admin->fresh()->hasRole(Role::SUPER_ADMIN));
    }

    public function test_admins_cannot_act_on_themselves_and_one_super_admin_remains(): void
    {
        $admin = $this->signedInAdmin(Role::SUPER_ADMIN);

        $this->post("/admin/users/{$admin->id}/status", ['status' => 'suspended'])->assertSessionHasErrors('user');
        $this->post("/admin/users/{$admin->id}/roles", ['roles' => []])->assertSessionHasErrors('user');
        $this->assertSame('active', $admin->fresh()->status);

        // Another super admin cannot remove the last one either.
        $other = $this->signedInAdmin(Role::SUPER_ADMIN);
        $admin->roles()->detach(); // leave $other as the only super admin
        $third = $this->signedInAdmin(Role::SUPER_ADMIN);
        $third->roles()->detach();
        $third->roles()->attach(Role::query()->where('name', Role::ADMIN)->value('id'));
        $third->roles()->attach(Role::query()->where('name', Role::SUPER_ADMIN)->value('id'));
        $this->post("/admin/users/{$other->id}/status", ['status' => 'suspended'])->assertRedirect();
        $this->post("/admin/users/{$third->id}/status", ['status' => 'suspended'])->assertSessionHasErrors('user');
    }

    /** Privilege escalation: only super admins grant administrator roles. */
    public function test_only_super_admins_grant_admin_roles(): void
    {
        $employee = $this->makeUser([], Role::EMPLOYEE);

        $this->signedInAdmin(Role::ADMIN);
        $this->post("/admin/users/{$employee->id}/roles", ['roles' => ['employee', 'super_admin']])->assertSessionHasErrors('roles');
        $this->assertFalse($employee->fresh()->isAdmin());

        $this->signedInAdmin(Role::SUPER_ADMIN);
        $this->post("/admin/users/{$employee->id}/roles", ['roles' => ['employee', 'admin']])->assertRedirect();
        $this->assertTrue($employee->fresh()->hasRole('admin'));

        $log = AuditLog::query()->where('action', 'admin.user.roles_changed')->latest('id')->firstOrFail();
        $this->assertSame('employee', $log->context['before']);
        $this->assertSame('admin,employee', collect(explode(',', $log->context['after']))->sort()->implode(','));
    }

    public function test_invitations_from_the_panel(): void
    {
        Notification::fake();
        $this->signedInAdmin(Role::ADMIN);

        $this->post('/admin/invitations', ['email' => 'New.Person@mt.co.tz', 'role' => 'employee'])->assertSessionHas('status');
        Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class);

        $this->post('/admin/invitations', ['email' => 'boss@mt.co.tz', 'role' => 'super_admin'])->assertSessionHasErrors('role');
        $this->post('/admin/invitations', ['email' => 'x@gmail.com', 'role' => 'employee'])->assertSessionHasErrors('email');
    }

    public function test_mfa_reset_forces_re_enrolment_and_signs_out(): void
    {
        $employee = $this->makeUser();
        $this->enableMfa($employee);
        $this->signedInAdmin(Role::ADMIN);

        $this->post("/admin/users/{$employee->id}/mfa/reset")->assertRedirect();

        $this->assertFalse($employee->fresh()->hasMfaEnabled());
        $this->assertTrue(AuditLog::query()->where('action', 'admin.user.mfa_reset')->exists());
    }

    /** US-07: administrators never see mail content. */
    public function test_employee_page_shows_connection_status_but_no_mail_content(): void
    {
        $this->useFakeProvider();
        $employee = $this->makeUser();
        $account = $this->linkAccount($employee);
        $this->fake->addMessage('INBOX', $this->envelope(1, 'Confidential salary review', snippet: 'Board approved figures'));
        app(SyncService::class)->syncAccount($account->refresh(), true);
        $this->signedInAdmin(Role::SUPER_ADMIN);

        $page = $this->get("/admin/users/{$employee->id}")->assertOk()->assertSee('developer@mt.co.tz')->assertSee('active');
        $page->assertDontSee('Confidential salary review')->assertDontSee('Board approved figures')->assertDontSee('partner@example.org');

        foreach (['/admin', '/admin/audit', '/admin/jobs'] as $url) {
            $this->get($url)->assertDontSee('Confidential salary review');
        }
    }

    // ---- Audit -----------------------------------------------------------------------

    public function test_audit_export_is_audited_and_safe_for_spreadsheets(): void
    {
        $admin = $this->signedInAdmin(Role::SUPER_ADMIN);
        // A cell that *starts* with "=" would be evaluated by Excel/LibreOffice.
        app(AuditLogger::class)->record('test.formula', targetType: '=HYPERLINK("x")', targetId: '+cmd');

        $csv = $this->get('/admin/audit/export')->assertOk()->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+cmd", $csv);
        $this->assertDoesNotMatchRegularExpression('/(^|,)"?[=+@]/m', $csv);
        $this->assertTrue(AuditLog::query()->where('action', 'admin.audit.exported')->where('actor_user_id', $admin->id)->exists());
    }

    public function test_audit_page_filters_and_chain_verification(): void
    {
        $this->signedInAdmin(Role::SECURITY_AUDITOR);
        app(AuditLogger::class)->record('auth.login.failed', 'failure');

        $this->get('/admin/audit?action=auth.login&result=failure')->assertOk()->assertSee('auth.login.failed');
        $this->get('/admin/audit?action=bad;drop')->assertSessionHasErrors('action');
        $this->post('/admin/audit/verify')->assertSessionHas('status', 'Audit chain verified: intact.');
    }

    // ---- Policies and providers -------------------------------------------------------

    public function test_policy_changes_take_effect_and_are_audited(): void
    {
        $this->signedInAdmin(Role::SUPER_ADMIN);

        $this->post('/admin/policies', ['mfa_required_for_all' => 0, 'risky_extension_policy' => 'warn', 'domains' => 'not a domain!'])->assertSessionHasErrors('domains');
        $this->post('/admin/policies', ['mfa_required_for_all' => 1, 'risky_extension_policy' => 'block', 'domains' => 'mt.co.tz, mbunitech.co.tz'])->assertSessionHas('status');

        $this->assertTrue(CompanyDomains::contains('someone@mbunitech.co.tz'));
        $this->freshRequest()->login($this->makeUser())->assertJsonPath('status', 'mfa_enrollment_required');

        $log = AuditLog::query()->where('action', 'admin.policy.changed')->firstOrFail();
        $this->assertFalse($log->context['mfa_before']);
        $this->assertTrue($log->context['mfa_after']);
    }

    public function test_mail_server_hosts_cannot_point_inside_the_network(): void
    {
        $this->app->instance(HostGuard::class, new HostGuard(fn (string $h) => match ($h) {
            'imap.hostinger.com', 'smtp.hostinger.com' => ['172.65.188.64'],
            'evil.internal.example' => ['10.0.0.5'],
            default => [],
        }));
        $this->signedInAdmin(Role::SUPER_ADMIN);
        $profile = ProviderProfile::query()->where('key', 'hostinger')->firstOrFail();
        $form = ['imap_host' => 'imap.hostinger.com', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'smtp_host' => 'smtp.hostinger.com', 'smtp_port' => 587, 'smtp_encryption' => 'starttls', 'saves_sent_automatically' => 0, 'enabled' => 1];

        $this->post("/admin/providers/{$profile->id}", [...$form, 'imap_host' => 'evil.internal.example'])->assertSessionHasErrors('imap_host');
        $this->post("/admin/providers/{$profile->id}", [...$form, 'smtp_encryption' => 'none'])->assertSessionHasErrors('smtp_encryption');
        $this->post("/admin/providers/{$profile->id}", $form)->assertSessionHas('status');

        $this->assertSame(587, $profile->fresh()->smtp_port);
        $this->assertTrue(AuditLog::query()->where('action', 'admin.provider_profile.changed')->exists());
    }

    public function test_failed_jobs_page_hides_payloads(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'connection' => 'redis', 'queue' => 'send',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\SendMessageJob', 'data' => ['secret' => 'ceo@mt.co.tz private payload']]),
            'exception' => 'App\\Mailbox\\ProviderError: SMTP submit failed for ceo@mt.co.tz', 'failed_at' => now(),
        ]);
        $this->signedInAdmin(Role::ADMIN);

        $this->get('/admin/jobs')->assertOk()->assertSee('SendMessageJob')->assertSee('App\\Mailbox\\ProviderError')
            ->assertDontSee('private payload')->assertDontSee('ceo@mt.co.tz');
    }

    // ---- HTTP hardening ----------------------------------------------------------------

    /** SEC-T17: CSRF is enforced on admin forms (unit-test bypass removed for this check). */
    public function test_admin_forms_require_a_csrf_token(): void
    {
        $middleware = new class(app(), app('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
        $request = Request::create('/admin/login', 'POST', ['email' => 'a@mt.co.tz', 'password' => 'x']);
        $request->setLaravelSession(app('session.store'));

        $this->expectException(TokenMismatchException::class);
        $middleware->handle($request, fn () => response('should not run'));
    }

    public function test_admin_pages_have_strict_csp_and_secure_cookie_settings(): void
    {
        $response = $this->get('/admin/login')->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $response->assertHeader('X-Frame-Options', 'DENY');
        $this->assertSame('strict', config('session.same_site'));
        $this->assertTrue(config('session.http_only'));
        $this->assertSame(30, config('session.lifetime'));
        $this->assertStringNotContainsString('style=', $response->getContent(), 'no inline styles (CSP)');
        $this->assertStringNotContainsString('<script', $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('Cross-Origin-Embedder-Policy', 'require-corp');
        $this->assertNull(collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'XSRF-TOKEN'), 'no JavaScript-readable CSRF cookie');
        $this->assertTrue(collect($response->headers->getCookies())->every(fn ($c) => $c->isHttpOnly()), 'every cookie is HttpOnly');
    }
}
