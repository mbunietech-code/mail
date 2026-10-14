<?php

namespace App\Http\Middleware\Admin;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin panel gate: an active user with an admin role who completed MFA in this
 * session. Admins always need MFA (FR-AUTH-008); without it they are sent to enrol.
 */
class EnsureAdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = Auth::guard('web')->user();

        if ($user === null) {
            return redirect()->guest(route('admin.login'));
        }

        if (! $user->isActive() || ! $user->isAdmin()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect()->route('admin.login')->withErrors(['email' => 'This account cannot use the admin panel.']);
        }

        if (! $user->hasMfaEnabled()) {
            return redirect()->route('admin.mfa.enroll');
        }

        if ($request->session()->get('admin_mfa_user') !== $user->id) {
            return redirect()->route('admin.mfa');
        }

        return $next($request);
    }
}
