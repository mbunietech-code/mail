<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\MfaService;
use App\Services\Auth\ReauthService;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class ReauthController extends Controller
{
    public function __construct(
        private readonly ReauthService $reauth,
        private readonly TokenService $tokens,
        private readonly MfaService $mfa,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required_without:code', 'nullable', 'string', 'max:256'],
            'code' => ['required_without:password', 'nullable', 'string', 'max:10'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $key = 'reauth:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('mbuniemail.throttle.mfa_per_minute'))) {
            throw ApiException::tooManyAttempts(RateLimiter::availableIn($key));
        }

        $ok = isset($data['password'])
            ? Hash::check($data['password'], $user->password)
            : $this->mfa->verify($user, $data['code']);

        if (! $ok) {
            RateLimiter::hit($key, 60);
            $this->audit->record('auth.reauth.failed', 'failure', $user, 'user', $user->id);

            throw new ApiException('INVALID_CREDENTIALS', 401, 'Confirmation failed.');
        }

        $family = $this->tokens->currentFamilyId($user);
        if ($family === null) {
            throw ApiException::forbidden();
        }

        RateLimiter::clear($key);
        $this->reauth->mark($family);

        return response()->json(null, 204);
    }
}
