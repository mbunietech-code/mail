<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\LoginThrottle;
use App\Services\Auth\MfaService;
use App\Services\Auth\RecoveryCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Admin panel sign-in: password, then TOTP (always required for admins), with
 * session regeneration at each privilege step (session fixation).
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly LoginThrottle $throttle,
        private readonly MfaService $mfa,
        private readonly RecoveryCodeService $recoveryCodes,
        private readonly AuditLogger $audit,
    ) {}

    public function showLogin(): View
    {
        return view('admin.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:256'],
        ]);
        $email = strtolower(trim($data['email']));
        $ip = (string) $request->ip();

        try {
            $this->throttle->ensureNotLimited($email, $ip);
        } catch (ApiException $e) {
            return back()->withErrors(['email' => 'Too many attempts. Try again in a few minutes.'])->onlyInput('email');
        }

        $user = User::query()->where('email', $email)->first();
        $valid = $user !== null && Hash::check($data['password'], $user->password);
        if ($user === null) {
            Hash::check($data['password'], Hash::make('timing-equaliser'));
        }

        if (! $valid || ! $user->isActive() || ! $user->isAdmin()) {
            $this->throttle->recordFailure($email, $ip);
            $this->audit->record('admin.login.failed', 'failure', $user, 'user', $user?->id, ['reason' => $valid ? 'not_admin_or_inactive' : 'invalid_credentials'], 'admin');

            // Same message for every failure: no hint about which part was wrong.
            return back()->withErrors(['email' => 'These credentials cannot sign in to the admin panel.'])->onlyInput('email');
        }

        $this->throttle->recordSuccess($email, $ip);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->forget(['admin_mfa_user', 'admin_reauth_at']);

        return $user->hasMfaEnabled() ? redirect()->route('admin.mfa') : redirect()->route('admin.mfa.enroll');
    }

    public function showMfa(): View
    {
        return view('admin.auth.mfa');
    }

    public function verifyMfa(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        /** @var User $user */
        $user = $request->user();

        $key = 'admin-mfa:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, (int) config('mbuniemail.throttle.mfa_per_minute'))) {
            return back()->withErrors(['code' => 'Too many attempts. Wait a minute.']);
        }

        $code = trim($data['code']);
        $ok = preg_match('/^\d{6}$/', $code) ? $this->mfa->verify($user, $code) : $this->recoveryCodes->consume($user, $code);
        if (! $ok) {
            RateLimiter::hit($key, 60);
            $this->audit->record('admin.mfa.failed', 'failure', $user, 'user', $user->id, actorType: 'admin');

            return back()->withErrors(['code' => 'The code is not valid.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('admin_mfa_user', $user->id);
        $request->session()->put('admin_reauth_at', now()->getTimestamp());
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('admin.login.success', 'success', $user, 'user', $user->id, actorType: 'admin');

        return redirect()->intended(route('admin.dashboard'));
    }

    /** First sign-in of a console-created administrator: enrol TOTP before anything else. */
    public function showEnroll(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->hasMfaEnabled()) {
            return redirect()->route('admin.mfa');
        }

        $enrollment = $request->session()->get('admin_enrollment') ?? $this->mfa->beginEnrollment($user);
        $request->session()->put('admin_enrollment', $enrollment);

        return view('admin.auth.enroll', ['secret' => $enrollment['secret'], 'uri' => $enrollment['otpauth_uri']]);
    }

    public function confirmEnroll(Request $request): View|RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:6']]);
        /** @var User $user */
        $user = $request->user();

        if ($user->hasMfaEnabled() || ! $this->mfa->confirmEnrollment($user, $data['code'])) {
            return back()->withErrors(['code' => 'The code is not valid. Check the time on your phone and try again.']);
        }

        $request->session()->forget('admin_enrollment');
        $request->session()->regenerate();
        $request->session()->put('admin_mfa_user', $user->id);
        $request->session()->put('admin_reauth_at', now()->getTimestamp());
        $codes = $this->recoveryCodes->regenerate($user);
        $this->audit->record('auth.mfa.enabled', 'success', $user, 'user', $user->id, ['via' => 'admin_panel'], 'admin');

        return view('admin.auth.recovery-codes', ['codes' => $codes]);
    }

    public function showReauth(): View
    {
        return view('admin.auth.reauth');
    }

    public function reauth(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:256'],
            'code' => ['required', 'string', 'size:6'],
        ]);
        /** @var User $user */
        $user = $request->user();

        $key = 'admin-reauth:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['password' => 'Too many attempts. Wait a minute.']);
        }

        if (! Hash::check($data['password'], $user->password) || ! $this->mfa->verify($user, $data['code'])) {
            RateLimiter::hit($key, 60);
            $this->audit->record('admin.reauth.failed', 'failure', $user, 'user', $user->id, actorType: 'admin');

            return back()->withErrors(['password' => 'Confirmation failed.']);
        }

        RateLimiter::clear($key);
        $request->session()->put('admin_reauth_at', now()->getTimestamp());

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($user !== null) {
            $this->audit->record('admin.logout', 'success', $user, 'user', $user->id, actorType: 'admin');
        }

        return redirect()->route('admin.login');
    }
}
