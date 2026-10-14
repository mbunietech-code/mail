<?php

namespace App\Security;

use JsonSerializable;
use LogicException;
use Stringable;

/**
 * Wraps a secret value so it cannot leak through string casting, var_dump, logging,
 * JSON encoding or serialization (e.g. into queued job payloads). Call reveal() only
 * at the point of use.
 */
final class Secret implements JsonSerializable, Stringable
{
    public function __construct(#[\SensitiveParameter] private string $value) {}

    public function reveal(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return '[secret]';
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => '[secret]'];
    }

    public function jsonSerialize(): string
    {
        return '[secret]';
    }

    /** @return array<never> */
    public function __serialize(): array
    {
        throw new LogicException('Secrets must not be serialized.');
    }
}
