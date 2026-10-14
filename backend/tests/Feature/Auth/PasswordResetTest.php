<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    private const NEW_PASSWORD = 'Brand-New-Passphrase-42';

    /** SEC-T33 */
    public function test_forgot_password_response_is_identical_for_unknown_accounts(): void
    {
        Notification::fake();
        $user = $this->makeUser();

        $known = $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertStatus(202);
        $unknown = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'ghost@mt.co.tz'])->assertStatus(202);

        $this->assertSame($known->json('status'), $unknown->json('status'));
        Notification::assertSentTo($user, ResetPasswordNotification::class);
        Notification::assertCount(1);
    }

    public function test_reset_link_goes_to_the_recovery_address_and_keeps_token_out_of_the_query_string(): void
    {
        Notification::fake();
        $user = $this->makeUser(['recovery_email' => 'personal@example.org']);

        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email]);

        Notification::assertSentTo($user, function (ResetPasswordNotification $n, array $channels, User $notifiable) {
            $url = $n->toMail($notifiable)->actionUrl;

            return $notifiable->routeNotificationForMail() === 'personal@example.org'
                && str_contains($url, '#token=') && ! str_contains($url, '?');
        });
    }

    /** SEC-T04, SEC-T06 */
    public function test_reset_is_single_use_and_revokes_all_sessions(): void
    {
        $user = $this->makeUser();
        $tokens = $this->tokensFor($user);
        $token = Password::broker()->createToken($user);

        $this->freshRequest()->postJson('/api/v1/auth/password/reset', $this->payload($user, $token))->assertNoContent();

        $this->freshRequest()->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertStatus(401);
        $this->freshRequest()->getJson('/api/v1/me', $this->bearer($tokens['access_token']))->assertStatus(401);
        $this->freshRequest()->postJson('/api/v1/auth/password/reset', $this->payload($user, $token))
            ->assertStatus(422)->assertJsonPath('code', 'RESET_INVALID');
        $this->freshRequest()->login($user, self::NEW_PASSWORD)->assertOk();
    }

    /** SEC-T05 */
    public function test_reset_token_expires_after_thirty_minutes(): void
    {
        $user = $this->makeUser();
        $token = Password::broker()->createToken($user);

        $this->travel(31)->minutes();

        $this->postJson('/api/v1/auth/password/reset', $this->payload($user, $token))->assertStatus(422);
    }

    /** SEC-T03 */
    public function test_breached_password_is_rejected(): void
    {
        $this->breachedPasswords = [self::NEW_PASSWORD];

        $user = $this->makeUser();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/password/reset', $this->payload($user, $token))
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_short_and_contextual_passwords_are_rejected(): void
    {
        $user = $this->makeUser(['email' => 'developer@mt.co.tz']);
        $token = Password::broker()->createToken($user);

        foreach (['Short-1', 'Developer-Strong-77', 'MbuniTech-Strong-77'] as $bad) {
            $this->postJson('/api/v1/auth/password/reset', $this->payload($user, $token, $bad))
                ->assertStatus(422)->assertJsonValidationErrors('password');
        }
    }

    /** @return array<string, string> */
    private function payload(User $user, string $token, string $password = self::NEW_PASSWORD): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password];
    }
}
