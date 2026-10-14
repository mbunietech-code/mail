<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Progressive login abuse controls (SRS FR-AUTH-001, risk R-01/R-16):
 *  - per account+IP and per IP attempt limits per minute (429)
 *  - temporary account lockout after repeated failures within an hour (423)
 * Lockouts are time-limited so they cannot be used to lock a user out permanently.
 */
class LoginThrottle
{
    public function ensureNotLimited(string $email, string $ip): void
    {
        $cfg = config('mbuniemail.throttle');
        $account = self::accountKey($email);

        if (RateLimiter::tooManyAttempts('login-lock:'.$account, 1)) {
            throw ApiException::tooManyAttempts(RateLimiter::availableIn('login-lock:'.$account), 'ACCOUNT_LOCKED', 423);
        }
        foreach (['login:'.$account.'|'.$ip => $cfg['login_per_minute'], 'login-ip:'.$ip => $cfg['login_ip_per_minute']] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ApiException::tooManyAttempts(RateLimiter::availableIn($key));
            }
        }
    }

    public function recordFailure(string $email, string $ip): void
    {
        $cfg = config('mbuniemail.throttle');
        $account = self::accountKey($email);

        RateLimiter::hit('login:'.$account.'|'.$ip, 60);
        RateLimiter::hit('login-ip:'.$ip, 60);

        if (RateLimiter::hit('login-fail:'.$account, 3600) >= $cfg['lockout_failures']) {
            RateLimiter::hit('login-lock:'.$account, $cfg['lockout_minutes'] * 60);
            RateLimiter::clear('login-fail:'.$account);
        }
    }

    public function recordSuccess(string $email, string $ip): void
    {
        $account = self::accountKey($email);
        RateLimiter::clear('login:'.$account.'|'.$ip);
        RateLimiter::clear('login-fail:'.$account);
    }

    private static function accountKey(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }
}
