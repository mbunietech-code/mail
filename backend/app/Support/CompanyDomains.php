<?php

namespace App\Support;

use App\Models\Organization;
use Illuminate\Support\Facades\Cache;

/**
 * The organisation's email domains — single source of truth (the organisations
 * table, editable by administrators). Config only seeds the initial value.
 */
final class CompanyDomains
{
    private const CACHE_KEY = 'company-domains';

    /** @return list<string> */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, function () {
            $domains = Organization::query()->value('allowed_domains');
            $list = is_string($domains) ? json_decode($domains, true) : $domains;

            return array_values(array_map('strtolower', is_array($list) && $list !== [] ? $list : config('mbuniemail.allowed_domains')));
        });
    }

    public static function contains(?string $email): bool
    {
        $at = $email === null ? false : strrpos($email, '@');

        return $at !== false && in_array(strtolower(substr((string) $email, $at + 1)), self::all(), true);
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
