<?php

namespace Tests\Feature\Ops;

use App\Models\AuditLog;
use Tests\TestCase;

class ConsoleCommandsTest extends TestCase
{
    public function test_console_mfa_reset_requires_reason_and_confirmation_and_is_audited(): void
    {
        $user = $this->makeUser(['email' => 'locked.out@mt.co.tz'], 'super_admin');
        $this->enableMfa($user);
        $user = $user->fresh();

        $this->artisan('mbuniemail:reset-mfa', ['email' => 'locked.out@mt.co.tz'])->assertFailed();
        $this->artisan('mbuniemail:reset-mfa', ['email' => 'locked.out@mt.co.tz', '--reason' => 'INC-42'])
            ->expectsConfirmation('Reset MFA for locked.out@mt.co.tz and sign them out everywhere?', 'no')->assertFailed();
        $this->assertTrue($user->fresh()->hasMfaEnabled());

        $this->artisan('mbuniemail:reset-mfa', ['email' => 'locked.out@mt.co.tz', '--reason' => 'INC-42'])
            ->expectsConfirmation('Reset MFA for locked.out@mt.co.tz and sign them out everywhere?', 'yes')->assertSuccessful();

        $this->assertFalse($user->fresh()->hasMfaEnabled());
        $log = AuditLog::query()->where('action', 'admin.user.mfa_reset')->sole();
        $this->assertSame('console', $log->context['via']);
        $this->assertSame('INC-42', $log->context['reason']);
    }
}
