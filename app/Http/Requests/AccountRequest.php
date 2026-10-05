<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return match ($this->route()->getName()) {
            'register.store' => ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'password' => ['required', 'confirmed', Password::min(12)]],
            'login.store' => ['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string'], 'remember' => ['sometimes', 'boolean']],
            'password.update' => ['email' => ['required', 'email', 'max:255'], 'token' => ['required', 'string'], 'password' => ['required', 'confirmed', Password::min(12)]],
            default => ['email' => ['required', 'email', 'max:255']],
        };
    }
}
