<?php

namespace Tests\Feature\Security;

use App\Services\Auth\TokenService;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * SEC-T09 / SEC-T11: every API route is explicitly classified, unauthenticated calls
 * are rejected, and MFA-pending tokens only reach the MFA routes they are meant for.
 */
class RouteSweepTest extends TestCase
{
    private const PUBLIC = ['auth.login', 'auth.refresh', 'auth.password.forgot', 'auth.password.reset', 'auth.invitations.accept', 'oauth.google.callback'];

    private const MFA_VERIFY = ['auth.mfa.verify'];

    private const MFA_ENROLL = ['auth.mfa.enroll', 'auth.mfa.confirm'];

    /** @return list<Route> */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            RouteFacade::getRoutes()->getRoutes(),
            fn (Route $r) => str_starts_with($r->uri(), 'api/'),
        ));
    }

    private function callRoute(Route $route, ?string $token = null): TestResponse
    {
        $uri = '/'.preg_replace('/\{[^}]+\}/', '01JAAAAAAAAAAAAAAAAAAAAAAA', $route->uri());
        $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');

        return $this->freshRequest()->json($method, $uri, [], $token ? $this->bearer($token) : []);
    }

    public function test_every_api_route_is_named_and_classified(): void
    {
        foreach ($this->apiRoutes() as $route) {
            $this->assertNotNull($route->getName(), "Route {$route->uri()} must be named so it can be classified.");

            $middleware = $route->gatherMiddleware();
            $isPublic = in_array($route->getName(), self::PUBLIC, true);

            $this->assertSame(! $isPublic, in_array('auth:sanctum', $middleware, true), "Route {$route->getName()} auth classification mismatch.");
            if (! $isPublic) {
                $this->assertNotEmpty(
                    array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'token:')),
                    "Route {$route->getName()} must declare a token ability.",
                );
            }
        }
    }

    public function test_protected_routes_reject_requests_without_a_token(): void
    {
        foreach ($this->apiRoutes() as $route) {
            if (in_array($route->getName(), self::PUBLIC, true)) {
                continue;
            }
            $this->callRoute($route)->assertStatus(401);
        }
    }

    public function test_mfa_pending_tokens_cannot_reach_full_access_routes(): void
    {
        $user = $this->makeUser();
        $verifyToken = app(TokenService::class)->issueMfaPending($user, TokenService::ABILITY_MFA_VERIFY, null);
        $enrollToken = app(TokenService::class)->issueMfaPending($user, TokenService::ABILITY_MFA_ENROLL, null);

        foreach ($this->apiRoutes() as $route) {
            $name = $route->getName();
            if (in_array($name, self::PUBLIC, true)) {
                continue;
            }

            if (! in_array($name, self::MFA_VERIFY, true)) {
                $this->assertContains($this->callRoute($route, $verifyToken)->status(), [401, 403], "mfa:verify token reached {$name}");
            }
            if (! in_array($name, self::MFA_ENROLL, true)) {
                $this->assertContains($this->callRoute($route, $enrollToken)->status(), [401, 403], "mfa:enroll token reached {$name}");
            }
        }
    }
}
