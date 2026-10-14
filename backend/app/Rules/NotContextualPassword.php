<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects passwords built from the user's own mailbox name or company words
 * (SRS FR-AUTH-011).
 */
class NotContextualPassword implements ValidationRule
{
    public function __construct(private readonly ?string $email = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $password = strtolower((string) $value);
        $words = config('mbuniemail.password.context_words', []);

        if ($this->email !== null) {
            $local = strtolower(strstr($this->email, '@', true) ?: $this->email);
            if (strlen($local) >= 3) {
                $words[] = $local;
            }
        }

        foreach ($words as $word) {
            if ($word !== '' && str_contains($password, strtolower($word))) {
                $fail('The :attribute must not contain your email name or company words.');

                return;
            }
        }
    }
}
