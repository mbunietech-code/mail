<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\MfaService;
use App\Services\Auth\ReauthService;
use App\Services\Auth\RecoveryCodeService;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class MfaController extends Controller
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly RecoveryCodeService $recoveryCodes,
        private readonly TokenService $tokens,
        private readonly ReauthService $reauth,
        private readonly AuditLogger $audit,
    ) {}

    /** Second login step, using the mfa-pending token from /auth/login. */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'max:10'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:32'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $this->ensureMfaAttemptsAvailable($user);

        $method = isset($data['code']) ? 'totp' : 'recovery_code';
        $ok = $method === 'totp'
            ? $this->mfa->verify($user, $data['code'])
            : $this->recoveryCodes->consume($user, $data['recovery_code']);

        if (! $ok) {
            $this->hitMfaLimiter($user);
            $this->audit->record('auth.mfa.failed', 'failure', $user, 'user', $user->id, ['method' => $method]);

            throw new ApiException('INVALID_MFA_CODE', 401, 'The verification code is invalid.');
        }

        $this->clearMfaLimiter($user);
        $issued = $this->tokens->issue($user, $this->deviceFromPendingToken($user), $request);
        $user->currentAccessToken()->delete();
        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->record('auth.login.success', 'success', $user, 'user', $user->id, ['method' => $method, 'session' => $issued->session->family_id]);
        if ($method === 'recovery_code') {
            $this->audit->record('auth.mfa.recovery_code_used', 'success', $user, 'user', $user->id, ['remaining' => $this->recoveryCodes->remaining($user)]);
        }

        return response()->json($issued->toResponse());
    }

    /** Starts enrolment: with an enrolment token, or a full token plus recent reauth. */
    public function enroll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->ensureEnrollmentAllowed($user);

        return response()->json($this->mfa->beginEnrollment($user));
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        /** @var User $user */
        $user = $request->user();
        $this->ensureEnrollmentAllowed($user);
        $this->ensureMfaAttemptsAvailable($user);

        if (! $this->mfa->confirmEnrollment($user, $data['code'])) {
            $this->hitMfaLimiter($user);

            throw new ApiException('INVALID_MFA_CODE', 422, 'The verification code is invalid.');
        }

        $this->clearMfaLimiter($user);
        $codes = $this->recoveryCodes->regenerate($user);
        $this->audit->record('auth.mfa.enabled', 'success', $user, 'user', $user->id);

        $body = ['recovery_codes' => $codes];

        // Completing policy-forced enrolment finishes the login.
        if ($user->currentAccessToken()->can(TokenService::ABILITY_MFA_ENROLL)) {
            $issued = $this->tokens->issue($user, $this->deviceFromPendingToken($user), $request);
            $user->currentAccessToken()->delete();
            $this->audit->record('auth.login.success', 'success', $user, 'user', $user->id, ['method' => 'mfa_enrollment', 'session' => $issued->session->family_id]);
            $body['tokens'] = $issued->toResponse();
        }

        return response()->json($body);
    }

    public function disable(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($this->mfa->isRequiredFor($user)) {
            throw ApiException::forbidden('MFA_POLICY_REQUIRED', 'Your organisation requires multi-factor authentication.');
        }

        $this->mfa->disable($user);
        $this->audit->record('auth.mfa.disabled', 'success', $user, 'user', $user->id);

        return response()->json(null, 204);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasMfaEnabled()) {
            throw new ApiException('MFA_NOT_ENABLED', 409, 'Multi-factor authentication is not enabled.');
        }

        $codes = $this->recoveryCodes->regenerate($user);
        $this->audit->record('auth.mfa.recovery_codes_regenerated', 'success', $user, 'user', $user->id);

        return response()->json(['recovery_codes' => $codes]);
    }

    private function ensureEnrollmentAllowed(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token->can(TokenService::ABILITY_MFA_ENROLL)) {
            return;
        }
        if ($user->hasMfaEnabled()) {
            throw new ApiException('MFA_ALREADY_ENABLED', 409, 'Multi-factor authentication is already enabled.');
        }
        if (! $this->reauth->isFresh($this->tokens->currentFamilyId($user))) {
            throw ApiException::forbidden('REAUTH_REQUIRED', 'Confirm your password to continue.');
        }
    }

    private function deviceFromPendingToken(User $user): ?Device
    {
        $token = $user->currentAccessToken();
        $deviceId = $token instanceof PersonalAccessToken ? explode('|', (string) $token->name, 2)[1] ?? '' : '';

        return $deviceId === '' ? null : Device::query()->where('user_id', $user->id)->find($deviceId);
    }

    private function ensureMfaAttemptsAvailable(User $user): void
    {
        $cfg = config('mbuniemail.throttle');
        foreach (['mfa-min:'.$user->id => $cfg['mfa_per_minute'], 'mfa-hour:'.$user->id => $cfg['mfa_per_hour']] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ApiException::tooManyAttempts(RateLimiter::availableIn($key));
            }
        }
    }

    private function hitMfaLimiter(User $user): void
    {
        RateLimiter::hit('mfa-min:'.$user->id, 60);
        RateLimiter::hit('mfa-hour:'.$user->id, 3600);
    }

    private function clearMfaLimiter(User $user): void
    {
        RateLimiter::clear('mfa-min:'.$user->id);
    }
}
