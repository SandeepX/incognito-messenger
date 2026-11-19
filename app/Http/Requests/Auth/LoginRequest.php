<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function authenticate(): void
    {
        $credentials = $this->only('email', 'password');

        if (! auth()->attempt($credentials, $this->boolean('remember'))) {
            $this->throwValidationExceptionWithMessage(
                'email',
                'These credentials do not match our records.',
            );
        }

        // Prevent session fixation: the session ID changes on every login.
        $this->session()->regenerate();
    }

    private function throwValidationExceptionWithMessage(string $field, string $message): never
    {
        throw ValidationException::withMessages([
            $field => $message,
        ]);
    }
}
