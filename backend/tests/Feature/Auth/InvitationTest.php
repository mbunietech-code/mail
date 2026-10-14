<?php

namespace Tests\Feature\Auth;

use App\Exceptions\ApiException;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Services\Auth\InvitationService;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    private function invite(string $email = 'new.hire@mt.co.tz'): string
    {
        Notification::fake();
        app(InvitationService::class)->invite($email, Role::query()->where('name', Role::EMPLOYEE)->firstOrFail(), null);

        $token = null;
        Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class, function (InvitationNotification $n) use (&$token) {
            preg_match('/#token=([A-Za-z0-9]+)/', $n->toMail(new AnonymousNotifiable)->actionUrl, $m);
            $token = $m[1] ?? null;

            return true;
        });

        return (string) $token;
    }

    public function test_invitation_can_be_accepted_once_and_assigns_the_role(): void
    {
        $token = $this->invite();
        $payload = ['token' => $token, 'name' => 'New Hire', 'password' => 'Welcome-Aboard-2026!', 'password_confirmation' => 'Welcome-Aboard-2026!'];

        $this->postJson('/api/v1/auth/invitations/accept', $payload)->assertCreated();
        $this->postJson('/api/v1/auth/invitations/accept', $payload)->assertStatus(422)->assertJsonPath('code', 'INVITATION_INVALID');

        $user = User::query()->where('email', 'new.hire@mt.co.tz')->sole();
        $this->assertTrue($user->hasRole(Role::EMPLOYEE));
        $this->assertSame(hash('sha256', $token), Invitation::query()->sole()->token_hash);
    }

    public function test_expired_invitation_is_rejected(): void
    {
        $token = $this->invite();
        $this->travel(73)->hours();

        $this->postJson('/api/v1/auth/invitations/accept', ['token' => $token, 'name' => 'X', 'password' => 'Welcome-Aboard-2026!', 'password_confirmation' => 'Welcome-Aboard-2026!'])
            ->assertStatus(422);
    }

    public function test_invitations_are_limited_to_allowed_domains(): void
    {
        $this->expectException(ApiException::class);

        app(InvitationService::class)->invite('outsider@gmail.com', Role::query()->firstOrFail(), null);
    }

    public function test_public_registration_does_not_exist(): void
    {
        $this->postJson('/api/v1/auth/register', ['email' => 'a@mt.co.tz', 'password' => 'x'])->assertStatus(404);
    }
}
