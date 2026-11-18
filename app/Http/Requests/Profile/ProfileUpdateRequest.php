<?php

namespace App\Http\Requests\Profile;

use App\Models\UserProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $profile = auth()->user()->profile;

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'alpha_dash', 'min:3', 'max:30',
                Rule::unique('user_profiles', 'username')->ignore($profile?->id),
            ],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore(auth()->id()),
            ],
            'bio' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'string', 'max:120'],
            'message_sounds' => ['boolean'],
            'show_active_status' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('username')) {
            $this->merge(['username' => strtolower(trim($this->input('username')))]);
        }
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }
}
