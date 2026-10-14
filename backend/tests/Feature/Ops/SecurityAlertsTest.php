<?php

namespace Tests\Feature\Ops;

use App\Services\Audit\AuditLogger;
use App\Services\Monitoring\SecurityAlerts;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SecurityAlertsTest extends TestCase
{
    private function audit(string $action, int $times = 1, string $result = 'success'): void
    {
        for ($i = 0; $i < $times; $i++) {
            app(AuditLogger::class)->record($action, $result, context: ['n' => $i]);
        }
    }

    public function test_quiet_system_raises_no_alerts(): void
    {
        $this->audit('auth.login.failed', 3, 'failure');

        $this->assertSame([], app(SecurityAlerts::class)->evaluate());
    }

    public function test_credential_stuffing_token_theft_and_admin_actions_are_detected(): void
    {
        $this->audit('auth.login.failed', 21, 'failure');
        $this->audit('security.refresh_reuse', 1, 'denied');
        $this->audit('admin.user.mfa_reset');

        $keys = array_column(app(SecurityAlerts::class)->evaluate(), 'key');

        $this->assertContains('failed-logins', $keys);
        $this->assertContains('refresh-reuse', $keys);
        $this->assertContains('admin-admin.user.mfa_reset', $keys, 'compensating control for single-admin MFA reset (R-24)');
    }

    public function test_old_events_outside_the_window_are_ignored(): void
    {
        $this->audit('security.refresh_reuse');
        $this->travel(15)->minutes();

        $this->assertSame([], app(SecurityAlerts::class)->evaluate(10));
    }

    public function test_alerts_are_emailed_once_and_contain_no_personal_data(): void
    {
        config(['mbuniemail.alerts.email' => 'security@mt.co.tz']);
        $sent = [];
        Event::listen(MessageSending::class, function ($e) use (&$sent) {
            $sent[] = $e->message->getTextBody() ?? (string) $e->message->getBody()->bodyToString();
        });
        $this->makeUser(['email' => 'victim@mt.co.tz']);
        $this->audit('security.refresh_reuse', 1, 'denied');

        $this->artisan('mbuniemail:security-alerts')->expectsOutputToContain('1 alert(s) sent')->assertSuccessful();
        $this->artisan('mbuniemail:security-alerts')->expectsOutputToContain('0 alert(s) sent'); // deduplicated

        $this->assertCount(1, $sent);
        $this->assertStringContainsString('refresh-token reuse', $sent[0]);
        $this->assertStringNotContainsString('victim@mt.co.tz', $sent[0]);
    }
}
