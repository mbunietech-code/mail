<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes tamper-evident audit records: each row's hash covers the previous row's
 * hash, so any edit or deletion breaks the chain (see verify()).
 *
 * Context is filtered to scalar values with non-sensitive keys — audit records must
 * never contain passwords, tokens, codes, subjects or message bodies.
 */
class AuditLogger
{
    private const SENSITIVE_KEY = '/pass|secret|token|otp|code|body|subject|snippet|cookie|authori|credential|key/i';

    private const MAX_CONTEXT_KEYS = 20;

    public function __construct(private readonly ?Request $request = null) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $action,
        string $result = 'success',
        ?User $actor = null,
        ?string $targetType = null,
        ?string $targetId = null,
        array $context = [],
        ?string $actorType = null,
    ): AuditLog {
        $actorType ??= $actor === null ? 'system' : ($actor->isAdmin() && str_starts_with($action, 'admin.') ? 'admin' : 'user');
        $ip = $this->request?->ip();

        $row = [
            'occurred_at' => Carbon::now(),
            'actor_user_id' => $actor?->id,
            'actor_type' => $actorType,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'result' => $result,
            'ip' => $ip !== null ? (inet_pton($ip) ?: null) : null,
            'user_agent' => $this->request ? mb_substr((string) $this->request->userAgent(), 0, 255) ?: null : null,
            'context' => self::sanitizeContext($context) ?: null,
        ];

        return DB::transaction(function () use ($row) {
            $head = DB::table('audit_chain_head')->where('id', 1)->lockForUpdate()->first();

            $row['id'] = (int) $head->last_id + 1;
            $row['prev_hash'] = $head->last_hash;
            $row['hash'] = self::hashRow($row);

            DB::table('audit_logs')->insert([
                ...$row,
                'occurred_at' => $row['occurred_at']->format('Y-m-d H:i:s.u'),
                'context' => $row['context'] === null ? null : json_encode($row['context']),
            ]);
            DB::table('audit_chain_head')->where('id', 1)->update(['last_id' => $row['id'], 'last_hash' => $row['hash']]);

            return AuditLog::query()->findOrFail($row['id']);
        });
    }

    /**
     * Re-computes the chain. Returns the id of the first broken row, or null if intact.
     */
    public function verify(): ?int
    {
        // Rows before the anchor were archived and pruned by mbuniemail:prune-audit.
        $head = DB::table('audit_chain_head')->where('id', 1)->first();
        $expectedPrev = (string) ($head->anchor_prev_hash ?? str_repeat('0', 64));
        $expectedId = (int) ($head->anchor_id ?? 1);
        $broken = null;

        AuditLog::query()->where('id', '>=', $expectedId)->orderBy('id')->chunk(1000, function ($logs) use (&$expectedPrev, &$expectedId, &$broken) {
            foreach ($logs as $log) {
                $row = [
                    'id' => $log->id,
                    'occurred_at' => $log->occurred_at,
                    'actor_user_id' => $log->actor_user_id,
                    'actor_type' => $log->actor_type,
                    'action' => $log->action,
                    'target_type' => $log->target_type,
                    'target_id' => $log->target_id,
                    'result' => $log->result,
                    'ip' => $log->ip,
                    'user_agent' => $log->user_agent,
                    'context' => $log->context,
                    'prev_hash' => $log->prev_hash,
                ];

                if ($log->id !== $expectedId || $log->prev_hash !== $expectedPrev || ! hash_equals(self::hashRow($row), $log->hash)) {
                    $broken = $log->id;

                    return false;
                }
                $expectedPrev = $log->hash;
                $expectedId++;
            }

            return true;
        });

        if ($broken === null) {
            if ((int) $head->last_id !== $expectedId - 1 || $head->last_hash !== $expectedPrev) {
                $broken = $expectedId; // trailing rows were removed
            }
        }

        return $broken;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function hashRow(array $row): string
    {
        $context = $row['context'];
        if (is_array($context)) {
            ksort($context);
        }

        $canonical = json_encode([
            $row['id'],
            $row['occurred_at']->format('Y-m-d H:i:s.u'),
            $row['actor_user_id'],
            $row['actor_type'],
            $row['action'],
            $row['target_type'],
            $row['target_id'],
            $row['result'],
            $row['ip'] === null ? null : bin2hex($row['ip']),
            $row['user_agent'],
            $context ?: null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return hash('sha256', $row['prev_hash'].$canonical);
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<string, scalar|null>
     */
    public static function sanitizeContext(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            if (count($clean) >= self::MAX_CONTEXT_KEYS) {
                break;
            }
            if (! is_string($key) || preg_match(self::SENSITIVE_KEY, $key) || ! (is_scalar($value) || $value === null)) {
                continue;
            }
            $clean[$key] = is_string($value) ? mb_substr($value, 0, 200) : $value;
        }

        return $clean;
    }
}
