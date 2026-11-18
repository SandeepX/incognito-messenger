<?php

namespace App\Http\Requests\Profile;

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
        $user = $this->user();
        $profile = $user?->profile;

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'alpha_dash', 'min:3', 'max:30',
                Rule::unique('user_profiles', 'username')->ignore($profile?->id),
            ],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'bio' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', 'string', 'max:60'],
            'show_active_status' => ['sometimes', 'boolean'],
            'read_receipts' => ['sometimes', 'boolean'],
            'message_request_policy' => ['sometimes', 'string', Rule::in(['everyone', 'friends_of_friends', 'nobody'])],
            'notes_visibility' => ['sometimes', 'string', Rule::in(['friends', 'nobody'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->has('name')) {
            $merge['name'] = trim((string) $this->input('name'));
        }
        if ($this->has('username')) {
            $merge['username'] = strtolower(trim((string) $this->input('username')));
        }
        if ($this->has('email')) {
            $merge['email'] = strtolower(trim((string) $this->input('email')));
        }
        foreach (['bio', 'status'] as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                $merge[$field] = $value === null ? null : trim((string) $value);
            }
        }

        if ($merge) {
            $this->merge($merge);
        }
    }
}
