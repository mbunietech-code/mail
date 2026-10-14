<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\RefreshSession;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Short-lived Sanctum access tokens plus rotating opaque refresh tokens.
 *
 * Refresh tokens are stored only as SHA-256 hashes. Every exchange marks the presented
 * token as rotated and issues a new one in the same family; presenting an already
 * rotated token is treated as theft and revokes the entire family (SDD §8.4).
 */
class TokenService
{
    public const ABILITY_MAIL = 'mail';

    public const ABILITY_MFA_VERIFY = 'mfa:verify';

    public const ABILITY_MFA_ENROLL = 'mfa:enroll';

    private const REFRESH_PREFIX = 'mbr_';

    public function __construct(private readonly AuditLogger $audit) {}

    public function issue(User $user, ?Device $device, Request $request, ?string $familyId = null): IssuedTokens
    {
        $cfg = config('mbuniemail.tokens');
        $plainRefresh = self::REFRESH_PREFIX.Str::random(64);

        $session = RefreshSession::query()->create([
            'user_id' => $user->id,
            'family_id' => $familyId ?? (string) Str::ulid(),
            'token_hash' => hash('sha256', $plainRefresh),
            'device_id' => $device?->id,
            'ip_prefix' => self::ipPrefix($request->ip()),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            'expires_at' => now()->addDays($cfg['refresh_absolute_ttl_days']),
            'idle_expires_at' => now()->addDays($cfg['refresh_idle_ttl_days']),
        ]);

        // Rotations keep the family's original absolute expiry.
        if ($familyId !== null) {
            $first = RefreshSession::query()->where('family_id', $familyId)->orderBy('created_at')->first();
            if ($first !== null && $first->expires_at->lt($session->expires_at)) {
                $session->forceFill(['expires_at' => $first->expires_at])->save();
            }
        }

        $ttl = (int) $cfg['access_ttl_minutes'];
        $access = $user->createToken('access', [self::ABILITY_MAIL], now()->addMinutes($ttl));
        /** @var PersonalAccessToken $model */
        $model = $access->accessToken;
        $model->forceFill(['refresh_session_id' => $session->id])->save();

        return new IssuedTokens(
            $user,
            $session,
            $access->plainTextToken,
            $ttl * 60,
            $plainRefresh,
            (int) max(0, now()->diffInSeconds($session->expires_at)),
        );
    }

    /**
     * Single-ability token used between password login and MFA verification/enrolment.
     */
    public function issueMfaPending(User $user, string $ability, ?Device $device): string
    {
        $minutes = (int) config('mbuniemail.tokens.mfa_pending_ttl_minutes');

        return $user->createToken('mfa-pending|'.($device->id ?? ''), [$ability], now()->addMinutes($minutes))->plainTextToken;
    }

    public function refresh(#[\SensitiveParameter] string $plainRefresh, Request $request): IssuedTokens
    {
        $hash = hash('sha256', $plainRefresh);

        /** @var array{0: string, 1: RefreshSession|null} $outcome */
        $outcome = DB::transaction(function () use ($hash) {
            $session = RefreshSession::query()->where('token_hash', $hash)->lockForUpdate()->first();

            if ($session === null) {
                return ['invalid', null];
            }
            if ($session->rotated_at !== null && $session->revoked_at === null) {
                return ['reuse', $session];
            }
            if (! $session->isUsable() || ! $session->user->isActive()) {
                return ['invalid', $session];
            }

            $session->forceFill(['rotated_at' => now()])->save();
            PersonalAccessToken::query()->where('refresh_session_id', $session->id)->delete();

            return ['ok', $session];
        });

        [$state, $session] = $outcome;

        if ($state === 'reuse') {
            $this->revokeFamily($session->family_id);
            $this->audit->record('security.refresh_reuse', 'denied', $session->user, 'refresh_session', $session->family_id);

            throw ApiException::unauthenticated('SESSION_REVOKED', 'Session revoked. Sign in again.');
        }
        if ($state !== 'ok') {
            throw ApiException::unauthenticated();
        }

        return $this->issue($session->user, $session->device, $request, $session->family_id);
    }

    public function revokeFamily(string $familyId): void
    {
        $ids = RefreshSession::query()->where('family_id', $familyId)->pluck('id');
        RefreshSession::query()->whereIn('id', $ids)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        PersonalAccessToken::query()->whereIn('refresh_session_id', $ids)->delete();
    }

    /**
     * Revokes every session of the user (optionally sparing one family), and all
     * access tokens including MFA-pending ones.
     */
    public function revokeAllForUser(User $user, ?string $exceptFamilyId = null): int
    {
        $families = RefreshSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->when($exceptFamilyId, fn ($q) => $q->where('family_id', '!=', $exceptFamilyId))
            ->distinct()
            ->pluck('family_id');

        foreach ($families as $family) {
            $this->revokeFamily($family);
        }

        PersonalAccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->when($exceptFamilyId, fn ($q) => $q->where(fn ($q) => $q
                ->whereNull('refresh_session_id')
                ->orWhereNotIn('refresh_session_id', RefreshSession::query()->select('id')->where('family_id', $exceptFamilyId))))
            ->delete();

        return $families->count();
    }

    /** Family id of the session behind the current access token, if any. */
    public function currentFamilyId(User $user): ?string
    {
        $token = $user->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token->refreshSession?->family_id : null;
    }

    /** Truncates to /24 (IPv4) or /48 (IPv6) — enough to recognise a network, not a host. */
    public static function ipPrefix(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return implode('.', array_slice(explode('.', $ip), 0, 3)).'.0/24';
        }
        $bin = inet_pton($ip);
        if ($bin === false) {
            return null;
        }

        return inet_ntop(substr($bin, 0, 6).str_repeat("\0", 10)).'/48';
    }
}
