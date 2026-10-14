<?php

namespace App\Http\Middleware\Admin;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Every admin write needs a password + TOTP confirmation in the last 10 minutes. */
class RequireAdminReauth
{
    public function handle(Request $request, Closure $next): Response
    {
        $at = (int) $request->session()->get('admin_reauth_at', 0);
        $window = (int) config('mbuniemail.tokens.reauth_window_minutes') * 60;

        if ($at === 0 || now()->getTimestamp() - $at > $window) {
            $request->session()->put('url.intended', url()->previous());

            return redirect()->route('admin.reauth')->with('status', 'Confirm your identity to continue.');
        }

        return $next($request);
    }
}
