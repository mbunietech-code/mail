<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Administrator-managed runtime settings (the `settings` table), cached briefly.
 * Values fall back to config defaults when unset.
 */
class Settings
{
    private const CACHE_KEY = 'app-settings';

    public function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::remember(self::CACHE_KEY, 60, fn () => DB::table('settings')
            ->pluck('value', 'key')
            ->map(fn ($v) => json_decode($v, true))
            ->all());

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function set(string $key, mixed $value, ?string $updatedBy = null): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value), 'updated_by' => $updatedBy, 'updated_at' => now()],
        );
        Cache::forget(self::CACHE_KEY);
    }
}
