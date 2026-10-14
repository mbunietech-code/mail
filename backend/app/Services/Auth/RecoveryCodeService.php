<?php

namespace App\Services\Auth;

use App\Models\RecoveryCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Single-use MFA recovery codes. Plaintext is returned once at generation; only
 * Argon2id hashes are stored.
 */
class RecoveryCodeService
{
    /** @return list<string> */
    public function regenerate(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < (int) config('mbuniemail.mfa.recovery_code_count'); $i++) {
            $hex = bin2hex(random_bytes(5));
            $codes[] = substr($hex, 0, 5).'-'.substr($hex, 5);
        }

        DB::transaction(function () use ($user, $codes) {
            $user->recoveryCodes()->delete();
            foreach ($codes as $code) {
                $user->recoveryCodes()->create(['code_hash' => Hash::make($code)]);
            }
        });

        return $codes;
    }

    public function consume(User $user, #[\SensitiveParameter] string $code): bool
    {
        $normalized = strtolower(preg_replace('/[^0-9a-f]/i', '', $code) ?? '');
        if (strlen($normalized) !== 10) {
            return false;
        }
        $normalized = substr($normalized, 0, 5).'-'.substr($normalized, 5);

        return DB::transaction(function () use ($user, $normalized) {
            $candidates = RecoveryCode::query()
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->lockForUpdate()
                ->get();

            foreach ($candidates as $candidate) {
                if (Hash::check($normalized, $candidate->code_hash)) {
                    $candidate->forceFill(['used_at' => now()])->save();

                    return true;
                }
            }

            return false;
        });
    }

    public function remaining(User $user): int
    {
        return $user->recoveryCodes()->whereNull('used_at')->count();
    }
}
