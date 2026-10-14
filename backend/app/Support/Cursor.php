<?php

namespace App\Support;

use App\Exceptions\ApiException;

/**
 * Opaque keyset-pagination cursor. Queries are always scoped to the caller, so a
 * tampered cursor can only page through the caller's own data.
 */
final class Cursor
{
    /** @param array<int, scalar> $values */
    public static function encode(array $values): string
    {
        return rtrim(strtr(base64_encode(json_encode($values, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array<int, scalar>|null */
    public static function decode(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $values = $json === false ? null : json_decode($json, true);
        if (! is_array($values) || count($values) !== 2 || ! is_scalar($values[0]) || ! is_string($values[1])) {
            throw new ApiException('VALIDATION_FAILED', 422, 'Invalid cursor.');
        }

        return $values;
    }
}
