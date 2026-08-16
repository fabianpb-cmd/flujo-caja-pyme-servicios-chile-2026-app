<?php

namespace App\Rules;

use App\Support\ChileanRut;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidChileanRut implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalized = ChileanRut::normalize(is_string($value) ? $value : (string) $value);

        if ($normalized === null || ! ChileanRut::isValid($normalized)) {
            $fail('El RUT ingresado no es válido. Revise el número y dígito verificador.');
        }
    }
}
