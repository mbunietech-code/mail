<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Auth\MfaService;
use App\Services\Auth\TokenService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deny-by-default token gate. Usage: `token:mail` or `token:mail,mfa:enroll`
 * (any-of). Also blocks inactive users and enforces the MFA policy on full tokens.
 */
class EnsureTokenAbility
{
    public function __construct(private readonly MfaService $mfa) {}

    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($user === null || ! $token instanceof PersonalAccessToken) {
            throw ApiException::unauthenticated();
        }

        if (! $user->isActive()) {
            $token->delete();

            throw ApiException::unauthenticated('ACCOUNT_DISABLED', 'This account is not active.');
        }

        $granted = collect($abilities)->first(fn (string $a) => $token->can($a));

        if ($granted === null) {
            throw match (true) {
                $token->can(TokenService::ABILITY_MFA_VERIFY) => ApiException::unauthenticated('MFA_REQUIRED', 'Multi-factor verification required.'),
                $token->can(TokenService::ABILITY_MFA_ENROLL) => ApiException::forbidden('MFA_ENROLLMENT_REQUIRED', 'You must set up multi-factor authentication first.'),
                default => ApiException::forbidden(),
            };
        }

        // Policy may have started requiring MFA after this token was issued.
        if ($granted === TokenService::ABILITY_MAIL
            && ! in_array(TokenService::ABILITY_MFA_ENROLL, $abilities, true)
            && ! $user->hasMfaEnabled()
            && $this->mfa->isRequiredFor($user)) {
            throw ApiException::forbidden('MFA_ENROLLMENT_REQUIRED', 'You must set up multi-factor authentication first.');
        }

        return $next($request);
    }
}
