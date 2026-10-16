<?php

namespace Tests;

use App\Mailbox\Fake\FakeProvider;
use App\Mailbox\ProviderFactory;
use App\Models\User;
use App\Security\KeyRing;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = DatabaseSeeder::class;

    public const PASSWORD = 'Correct-Horse-Battery-9';

    /** @var list<string> Passwords the fake HIBP range API reports as breached. */
    protected array $breachedPasswords = [];

    private static ?string $kekPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Per-run throwaway credential KEK, outside the repository.
        if (self::$kekPath === null) {
            self::$kekPath = sys_get_temp_dir().'/mbuniemail-test-kek-'.getmypid().'.json';
            @unlink(self::$kekPath);
            KeyRing::rotate(self::$kekPath);
        }
        config(['mbuniemail.credentials.kek_path' => self::$kekPath]);
        $this->app->forgetInstance(KeyRing::class);

        $this->resetHttpFakes();

        // Sign-in checks the password with the mail provider; the fake accepts only PASSWORD.
        ProviderFactory::fake(new FakeProvider, self::PASSWORD);
    }

    /**
     * Replaces all HTTP fakes (stubs otherwise accumulate and the first match wins).
     * No test may reach the network; the fake HIBP range API (k-anonymity) reports
     * only the passwords listed in $breachedPasswords.
     *
     * @param  array<string, mixed>  $stubs
     */
    protected function resetHttpFakes(array $stubs = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs + ['api.pwnedpasswords.com/range/*' => function ($request) {
            $prefix = substr((string) parse_url($request->url(), PHP_URL_PATH), -5);
            $lines = collect($this->breachedPasswords)
                ->map(fn (string $p) => strtoupper(sha1($p)))
                ->filter(fn (string $h) => str_starts_with($h, $prefix))
                ->map(fn (string $h) => substr($h, 5).':1234');

            return Http::response($lines->implode("\r\n"), 200);
        }]);
    }

    protected function tearDown(): void
    {
        ProviderFactory::fake(null);

        parent::tearDown();
    }

    /** Full token pair plus a fresh re-authentication window. */
    protected function reauthedTokensFor(User $user): array
    {
        $tokens = $this->tokensFor($user);
        $this->freshRequest()->postJson('/api/v1/auth/reauth', ['password' => self::PASSWORD], $this->bearer($tokens['access_token']))->assertNoContent();

        return $tokens;
    }

    /** @param  array<string, mixed>  $attributes */
    protected function makeUser(array $attributes = [], ?string $role = null): User
    {
        $factory = User::factory();
        if ($role !== null) {
            $factory = $factory->withRole($role);
        }

        return $factory->create($attributes)->fresh();
    }

    protected function login(User $user, string $password = self::PASSWORD): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $password,
            'device' => ['id' => 'test-device-1', 'platform' => 'android', 'name' => 'Test phone'],
        ]);
    }

    /** @return array<string, mixed> Full token response for a user without MFA. */
    protected function tokensFor(User $user): array
    {
        return $this->login($user)->assertOk()->assertJsonPath('status', 'authenticated')->json();
    }

    /** @return array<string, string> */
    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    protected function enableMfa(User $user): string
    {
        $secret = app(Google2FA::class)->generateSecretKey(32);
        $user->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => now()])->save();

        return $secret;
    }

    protected function totp(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    /** Sanctum caches the authenticated user per guard; reset between requests in one test. */
    protected function freshRequest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }
}
