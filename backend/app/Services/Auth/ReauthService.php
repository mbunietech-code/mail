<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;

/**
 * Tracks recent re-authentication per session family (SRS FR-AUTH-010).
 */
class ReauthService
{
    public function mark(string $familyId): void
    {
        Cache::put($this->key($familyId), now()->getTimestamp(), now()->addMinutes($this->window()));
    }

    public function isFresh(?string $familyId): bool
    {
        return $familyId !== null && Cache::has($this->key($familyId));
    }

    public function clear(string $familyId): void
    {
        Cache::forget($this->key($familyId));
    }

    private function key(string $familyId): string
    {
        return 'reauth:'.$familyId;
    }

    private function window(): int
    {
        return (int) config('mbuniemail.tokens.reauth_window_minutes');
    }
}
