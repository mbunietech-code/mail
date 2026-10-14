<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\LoginThrottle;
use App\Services\Auth\MfaService;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly MfaService $mfa,
        private readonly LoginThrottle $throttle,
        private readonly AuditLogger $audit,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:256'],
            'device' => ['sometimes', 'array'],
            'device.id' => ['required_with:device', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'device.platform' => ['required_with:device', 'in:android,windows,other'],
            'device.name' => ['nullable', 'string', 'max:80'],
        ]);

        $email = strtolower(trim($data['email']));
        $ip = (string) $request->ip();
        $this->throttle->ensureNotLimited($email, $ip);

        $user = User::query()->where('email', $email)->first();

        // Always perform one hash verification so response time does not reveal
        // whether the account exists (risk R-17).
        if ($user === null) {
            Hash::check($data['password'], self::dummyHash());
            $valid = false;
        } else {
            $valid = Hash::check($data['password'], $user->password);
        }

        if (! $valid) {
            $this->throttle->recordFailure($email, $ip);
            $this->audit->record('auth.login.failed', 'failure', $user, 'user', $user?->id, ['reason' => 'invalid_credentials']);

            throw new ApiException('INVALID_CREDENTIALS', 401, 'Incorrect email or password.');
        }

        if (! $user->isActive()) {
            $this->audit->record('auth.login.failed', 'denied', $user, 'user', $user->id, ['reason' => 'status_'.$user->status]);

            throw ApiException::forbidden('ACCOUNT_DISABLED', 'This account is not active. Contact your administrator.');
        }

        $this->throttle->recordSuccess($email, $ip);

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $data['password']])->save();
        }

        $device = isset($data['device']) ? $this->upsertDevice($user, $data['device']) : null;

        if ($user->hasMfaEnabled()) {
            $this->audit->record('auth.login.password_ok', 'success', $user, 'user', $user->id, ['next' => 'mfa']);

            return response()->json([
                'status' => 'mfa_required',
                'mfa_token' => $this->tokens->issueMfaPending($user, TokenService::ABILITY_MFA_VERIFY, $device),
                'expires_in' => (int) config('mbuniemail.tokens.mfa_pending_ttl_minutes') * 60,
            ]);
        }

        if ($this->mfa->isRequiredFor($user)) {
            $this->audit->record('auth.login.password_ok', 'success', $user, 'user', $user->id, ['next' => 'mfa_enrollment']);

            return response()->json([
                'status' => 'mfa_enrollment_required',
                'mfa_token' => $this->tokens->issueMfaPending($user, TokenService::ABILITY_MFA_ENROLL, $device),
                'expires_in' => (int) config('mbuniemail.tokens.mfa_pending_ttl_minutes') * 60,
            ]);
        }

        $issued = $this->tokens->issue($user, $device, $request);
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('auth.login.success', 'success', $user, 'user', $user->id, ['method' => 'password', 'session' => $issued->session->family_id]);

        return response()->json($issued->toResponse());
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $family = $this->tokens->currentFamilyId($user);

        if ($family !== null) {
            $this->tokens->revokeFamily($family);
        }
        $user->currentAccessToken()->delete();
        $this->audit->record('auth.logout', 'success', $user, 'refresh_session', $family);

        return response()->json(null, 204);
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string', 'max:128']]);

        return response()->json($this->tokens->refresh($data['refresh_token'], $request)->toResponse());
    }

    /**
     * @param  array{id: string, platform: string, name?: string|null}  $device
     */
    private function upsertDevice(User $user, array $device): Device
    {
        return Device::query()->updateOrCreate(
            ['user_id' => $user->id, 'client_device_id' => $device['id']],
            ['platform' => $device['platform'], 'name' => $device['name'] ?? null, 'last_seen_at' => now()],
        );
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('mbuniemail-timing-equaliser');
    }
}
