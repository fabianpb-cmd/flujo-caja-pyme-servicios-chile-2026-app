<?php

namespace App\Support\Security;

use App\Rules\NotCommonPassword;
use Illuminate\Validation\Rules\Password;

class PasswordPolicy
{
    /**
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return [
            'string',
            Password::min(15)->max(128),
            new NotCommonPassword(),
        ];
    }
}
