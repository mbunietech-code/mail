<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Notifications\ResetPasswordNotification;
use App\Services\Auth\InvitationService;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** US-01: the links in invitation and reset emails lead to working pages. */
class OnboardingPagesTest extends TestCase
{
    private const NEW_PASSWORD = 'Welcome-Aboard-2026!';

    public function test_invitation_email_link_leads_to_a_page_that_creates_the_account(): void
    {
        Notification::fake();
        app(InvitationService::class)->invite('new.hire@mt.co.tz', Role::query()->where('name', 'employee')->firstOrFail(), null);

        $url = null;
        Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class, function (InvitationNotification $n) use (&$url) {
            $url = $n->toMail(new AnonymousNotifiable)->actionUrl;

            return true;
        });
        [$page, $fragment] = explode('#', (string) $url, 2);
        parse_str($fragment, $f);

        $this->assertStringEndsWith('/app/invite', $page);
        $response = $this->get(parse_url($page, PHP_URL_PATH))->assertOk()->assertSee('Join MbunieMail');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('fragment-token.js', $response->getContent());

        $this->post('/app/invite', ['token' => $f['token'], 'name' => 'New Hire', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertSessionHas('done', 'new.hire@mt.co.tz');
        $this->assertTrue(User::query()->where('email', 'new.hire@mt.co.tz')->firstOrFail()->hasRole('employee'));

        // Single use, and weak passwords are refused with a readable error.
        $this->post('/app/invite', ['token' => $f['token'], 'name' => 'X', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])->assertSessionHasErrors('token');
    }

    public function test_weak_password_on_the_invitation_page_is_explained(): void
    {
        Notification::fake();
        app(InvitationService::class)->invite('second.hire@mt.co.tz', Role::query()->where('name', 'employee')->firstOrFail(), null);
        $token = null;
        Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class, function (InvitationNotification $n) use (&$token) {
            preg_match('/#token=([A-Za-z0-9]+)/', $n->toMail(new AnonymousNotifiable)->actionUrl, $m);
            $token = $m[1];

            return true;
        });

        $this->post('/app/invite', ['token' => $token, 'name' => 'Second', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->assertFalse(User::query()->where('email', 'second.hire@mt.co.tz')->exists());
    }

    public function test_reset_email_link_leads_to_a_page_that_changes_the_password(): void
    {
        Notification::fake();
        $user = $this->makeUser();
        $tokens = $this->tokensFor($user);
        $this->freshRequest()->postJson('/api/v1/auth/password/forgot', ['email' => $user->email]);

        $url = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use ($user, &$url) {
            $url = $n->toMail($user)->actionUrl;

            return true;
        });
        [$page, $fragment] = explode('#', (string) $url, 2);
        parse_str($fragment, $f);
        $this->get(parse_url($page, PHP_URL_PATH))->assertOk()->assertSee('Reset your password');

        $this->post('/app/reset-password', ['email' => $user->email, 'token' => $f['token'], 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertSessionHas('done');

        $this->freshRequest()->getJson('/api/v1/me', $this->bearer($tokens['access_token']))->assertStatus(401);
        $this->freshRequest()->login($user->fresh(), self::NEW_PASSWORD)->assertOk();
    }
}
