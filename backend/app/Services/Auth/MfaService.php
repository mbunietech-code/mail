<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

/**
 * RFC 6238 TOTP. Secrets are stored with the `encrypted` cast; the last accepted
 * time-step is persisted so a code can never be used twice (replay protection).
 */
class MfaService
{
    public function __construct(private readonly Google2FA $totp) {}

    /** @return array{secret: string, otpauth_uri: string} */
    public function beginEnrollment(User $user): array
    {
        $secret = $this->totp->generateSecretKey(32);
        $user->forceFill(['mfa_pending_secret' => $secret])->save();

        return [
            'secret' => $secret,
            'otpauth_uri' => $this->totp->getQRCodeUrl((string) config('mbuniemail.mfa.issuer'), $user->email, $secret),
        ];
    }

    public function confirmEnrollment(User $user, #[\SensitiveParameter] string $code): bool
    {
        return DB::transaction(function () use ($user, $code) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($locked->mfa_pending_secret === null) {
                return false;
            }

            // Baseline 0 (never null): with a null baseline the library returns `true`
            // instead of the matched time-step, which would break replay protection.
            $step = $this->totp->verifyKeyNewer($locked->mfa_pending_secret, $code, 0, (int) config('mbuniemail.mfa.window'));
            if ($step === false) {
                return false;
            }

            $locked->forceFill([
                'mfa_secret' => $locked->mfa_pending_secret,
                'mfa_pending_secret' => null,
                'mfa_confirmed_at' => now(),
                'mfa_last_used_step' => (int) $step,
            ])->save();
            $user->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    public function verify(User $user, #[\SensitiveParameter] string $code): bool
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        return DB::transaction(function () use ($user, $code) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $locked->hasMfaEnabled()) {
                return false;
            }

            $step = $this->totp->verifyKeyNewer(
                (string) $locked->mfa_secret,
                $code,
                $locked->mfa_last_used_step ?? 0,
                (int) config('mbuniemail.mfa.window'),
            );
            if ($step === false) {
                return false;
            }

            $locked->forceFill(['mfa_last_used_step' => (int) $step])->save();

            return true;
        });
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'mfa_secret' => null,
            'mfa_pending_secret' => null,
            'mfa_confirmed_at' => null,
            'mfa_last_used_step' => null,
        ])->save();
        $user->recoveryCodes()->delete();
    }

    /** Whether policy forces this user to have MFA (always true for admin roles). */
    public function isRequiredFor(User $user): bool
    {
        return $user->isAdmin() || (bool) app(Settings::class)->get('mfa.required_for_all', config('mbuniemail.mfa.required_for_all'));
    }
}
