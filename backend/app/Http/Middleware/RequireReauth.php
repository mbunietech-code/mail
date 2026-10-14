<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\Auth\ReauthService;
use App\Services\Auth\TokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Sensitive actions require POST /auth/reauth within the configured window. */
class RequireReauth
{
    public function __construct(
        private readonly ReauthService $reauth,
        private readonly TokenService $tokens,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->reauth->isFresh($this->tokens->currentFamilyId($user))) {
            throw ApiException::forbidden('REAUTH_REQUIRED', 'Confirm your password or MFA code to continue.');
        }

        return $next($request);
    }
}
