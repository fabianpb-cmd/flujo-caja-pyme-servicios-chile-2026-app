<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NotCommonPassword implements ValidationRule
{
    /**
     * SHA-256 hashes keep the small local deny-list out of logs and messages.
     * This is intentionally offline so password changes never depend on a remote service.
     *
     * @var list<string>
     */
    private const COMMON_PASSWORD_HASHES = [
        '2e2b24f8ee40bb847fe85bb23336a39ef5948e6b49d897419ced68766b16967a',
        'e27a7686b8028cfee7b57d954c3abccfb2a701968925f52bbd482e77be5de0bb',
        '1f7ce3e5fe60a6098f31f0a96d9c3d72292f2f87b69dce078b68ee42c8bd79f1',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (in_array(hash('sha256', mb_strtolower($value)), self::COMMON_PASSWORD_HASHES, true)) {
            $fail('La contraseña elegida es demasiado común.');
        }
    }
}
