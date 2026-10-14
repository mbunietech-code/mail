<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Security\KeyRing;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KeyRing::class, fn () => new KeyRing((string) config('mbuniemail.credentials.kek_path')));
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Password::defaults(function () {
            $rule = Password::min((int) config('mbuniemail.password.min_length'));

            return config('mbuniemail.password.check_breached') ? $rule->uncompromised() : $rule;
        });

        $this->configureRateLimiting();

        // Operational permissions only — there is deliberately no gate for mail content
        // and no Gate::before() super-user bypass (SDD §5.2).
        foreach (array_keys(Permission::ALL) as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
    }

    private function configureRateLimiting(): void
    {
        $throttle = config('mbuniemail.throttle');

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute($throttle['api_per_minute'])
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        RateLimiter::for('auth-public', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perHour($throttle['password_reset_per_hour'])->by('pr-email:'.hash('sha256', strtolower((string) $request->input('email')))),
            Limit::perHour(10)->by('pr-ip:'.$request->ip()),
        ]);
    }
}
