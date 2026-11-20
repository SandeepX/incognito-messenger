<?php

namespace App\Support;

final class AvatarUpload
{
    public const MAX_KILOBYTES = 10240;

    public const MAX_LABEL = '10 MB';

    public static function rules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'file',
            'image',
            'mimes:jpeg,png,gif,webp',
            'max:'.self::MAX_KILOBYTES,
        ];
    }

    public static function messages(): array
    {
        return [
            'avatar.max' => 'The picture may not be larger than '.self::MAX_LABEL.'.',
            'avatar.mimes' => 'The picture must be a JPEG, PNG, GIF or WebP image.',
            'avatar.image' => 'The picture must be a valid image file.',
        ];
    }
}
