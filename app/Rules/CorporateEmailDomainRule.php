<?php

namespace App\Rules;

use App\Support\Security\CorporateEmailDomain;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CorporateEmailDomainRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! CorporateEmailDomain::isAllowed($value)) {
            $fail('El correo debe pertenecer al dominio corporativo @tdatconsulting.cl.');
        }
    }
}
