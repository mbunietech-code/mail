<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\RefreshSession;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A "session" exposed to clients is a refresh-token family; its id is the family id
 * (stable across rotations).
 */
class SessionController extends Controller
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $current = $this->tokens->currentFamilyId($user);

        $sessions = RefreshSession::query()
            ->where('user_id', $user->id)
            ->usable()
            ->with('device')
            ->latest()
            ->get()
            ->map(fn (RefreshSession $s) => [
                'id' => $s->family_id,
                'device_name' => $s->device?->name,
                'platform' => $s->device?->platform,
                'ip_prefix' => $s->ip_prefix,
                'last_seen_at' => $s->created_at?->toIso8601String(),
                'current' => $s->family_id === $current,
            ]);

        return response()->json(['data' => $sessions]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless(
            RefreshSession::query()->where('user_id', $user->id)->where('family_id', $id)->whereNull('revoked_at')->exists(),
            404,
        );

        $this->tokens->revokeFamily($id);
        $this->audit->record('auth.session.revoked', 'success', $user, 'refresh_session', $id);

        return response()->json(null, 204);
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $count = $this->tokens->revokeAllForUser($user, $this->tokens->currentFamilyId($user));
        $this->audit->record('auth.session.revoked_others', 'success', $user, 'user', $user->id, ['count' => $count]);

        return response()->json(null, 204);
    }
}
