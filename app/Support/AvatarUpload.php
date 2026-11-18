<?php

namespace App\Support;

/**
 * Single source of truth for avatar upload constraints.
 *
 * The PHP upload limit and nginx body limit are deliberately higher than this
 * (see docker/php/uploads.ini and docker/nginx/default.conf) so Laravel gets
 * the chance to validate an oversized file and return a clear error instead
 * of PHP rejecting it first with a generic "failed to upload" message.
 */
final class AvatarUpload
{
    /** Maximum accepted size, in kilobytes (10 MB). */
    public const MAX_KILOBYTES = 10240;

    /** Human-readable limit for labels and error messages. */
    public const MAX_LABEL = '10 MB';

    /** @return array<int, string> */
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

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'avatar.max' => 'The picture may not be larger than '.self::MAX_LABEL.'.',
            'avatar.mimes' => 'The picture must be a JPEG, PNG, GIF or WebP image.',
            'avatar.image' => 'The picture must be a valid image file.',
        ];
    }
}
