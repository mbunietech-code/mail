<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    public function test_chain_verifies_when_untouched(): void
    {
        $audit = app(AuditLogger::class);
        $user = $this->makeUser();

        $audit->record('auth.login.success', 'success', $user, 'user', $user->id, ['method' => 'password']);
        $audit->record('auth.logout', 'success', $user);
        $audit->record('system.purge', 'success');

        $this->assertNull($audit->verify());
    }

    /** SEC-T48 */
    public function test_modified_row_breaks_the_chain(): void
    {
        $audit = app(AuditLogger::class);
        $audit->record('a.one');
        $second = $audit->record('a.two');
        $audit->record('a.three');

        DB::table('audit_logs')->where('id', $second->id)->update(['result' => 'failure']);

        $this->assertSame($second->id, $audit->verify());
    }

    public function test_deleted_rows_break_the_chain(): void
    {
        $audit = app(AuditLogger::class);
        $audit->record('a.one');
        $audit->record('a.two');
        $last = $audit->record('a.three');

        DB::table('audit_logs')->where('id', $last->id)->delete();

        $this->assertNotNull($audit->verify());
    }

    public function test_eloquent_updates_and_deletes_are_refused(): void
    {
        $log = app(AuditLogger::class)->record('a.one');

        $this->expectException(LogicException::class);
        $log->forceFill(['result' => 'failure'])->save();
    }

    public function test_sensitive_context_keys_and_non_scalars_are_dropped(): void
    {
        $log = app(AuditLogger::class)->record('a.ctx', context: [
            'method' => 'password',
            'password' => 'hunter2',
            'refresh_token' => 'mbr_x',
            'mfa_code' => '123456',
            'subject' => 'Quarterly results',
            'nested' => ['a' => 1],
            'note' => str_repeat('x', 500),
        ]);

        $context = AuditLog::query()->findOrFail($log->id)->context;
        $this->assertSame(['method', 'note'], array_keys($context));
        $this->assertSame(200, mb_strlen($context['note']));
    }
}
