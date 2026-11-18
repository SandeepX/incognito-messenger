<?php

namespace App\Http\Requests\Profile;

use App\Support\AvatarUpload;
use Illuminate\Foundation\Http\FormRequest;

class AvatarUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'avatar' => AvatarUpload::rules(true),
        ];
    }

    public function messages(): array
    {
        return AvatarUpload::messages();
    }
}
