<?php

namespace App\Support;

use App\Rules\NotContextualPassword;
use Illuminate\Validation\Rules\Password;

final class Passwords
{
    /**
     * Validation rules for a new password belonging to $email.
     *
     * @return list<mixed>
     */
    public static function rules(?string $email): array
    {
        return ['required', 'string', 'max:256', 'confirmed', Password::defaults(), new NotContextualPassword($email)];
    }
}
