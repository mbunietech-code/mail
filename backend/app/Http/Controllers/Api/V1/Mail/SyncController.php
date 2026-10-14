<?php

namespace App\Http\Controllers\Api\V1\Mail;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Jobs\SyncAccountJob;
use App\Models\EmailAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class SyncController extends Controller
{
    /** POST /accounts/{id}/sync — manual refresh (FR-SYNC-008), 6 per minute per account. */
    public function trigger(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $user->emailAccounts()->findOrFail($id);

        $key = 'sync-refresh:'.$account->id;
        if (RateLimiter::tooManyAttempts($key, 6)) {
            throw ApiException::tooManyAttempts(RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);

        SyncAccountJob::dispatch($account->id, true);

        return response()->json(['status' => 'queued'], 202);
    }

    /** GET /sync/status — cheap polling endpoint for the clients (FR-SYNC-007). */
    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'accounts' => $user->emailAccounts()->with(['folders' => fn ($q) => $q->where('role', 'inbox')])->get()->map(fn (EmailAccount $a) => [
                'id' => $a->id,
                'status' => $a->status,
                'last_error_code' => $a->last_error_code,
                'last_synced_at' => $a->last_synced_at?->toIso8601String(),
                'unread_inbox' => (int) $a->folders->sum('unread_count'),
            ])->values(),
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
