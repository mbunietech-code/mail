<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureTokenAbility;
use App\Http\Middleware\ForceJsonAccept;
use App\Http\Middleware\RequireReauth;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Problem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->prependToGroup('api', ForceJsonAccept::class);

        // Trusted reverse proxies come from config/trustedproxy.php.

        // Web (admin panel) redirects; API requests always receive problem+json instead.
        $middleware->redirectGuestsTo(fn () => route('admin.login'));
        $middleware->redirectUsersTo(fn () => route('admin.dashboard'));

        $middleware->alias([
            'token' => EnsureTokenAbility::class,
            'reauth' => RequireReauth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([ApiException::class]);
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'code', 'recovery_code', 'token', 'refresh_token']);

        $exceptions->render(function (Throwable $e, Request $request) {
            return $request->is('api/*') ? Problem::render($e, $request) : null;
        });
    })->create();
