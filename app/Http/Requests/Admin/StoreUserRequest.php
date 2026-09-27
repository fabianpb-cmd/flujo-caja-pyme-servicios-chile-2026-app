<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Support\Security\PasswordPolicy;
use App\Rules\CorporateEmailDomainRule;
use App\Support\Security\CorporateEmailDomain;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', new CorporateEmailDomainRule(), Rule::unique('users', 'email')],
            'role' => ['required', 'string', Rule::in(['admin', 'user'])],
            'password' => ['required', ...PasswordPolicy::rules(), 'confirmed'],
            'active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => CorporateEmailDomain::normalize((string) $this->input('email')),
            'active' => $this->boolean('active'),
        ]);
    }
}
