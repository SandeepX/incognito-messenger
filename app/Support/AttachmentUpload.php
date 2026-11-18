<?php

namespace App\Support;

/** Shared validation rules for message attachments stored in MinIO. */
final class AttachmentUpload
{
    public const MAX_KILOBYTES = 20480; // 20 MB

    public const MAX_LABEL = '20 MB';

    public const IMAGE_MIMES = 'jpeg,png,gif,webp';

    public const FILE_MIMES = 'pdf,doc,docx,xls,xlsx,txt,csv,zip';

    public static function rules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'file',
            'mimes:'.self::IMAGE_MIMES.','.self::FILE_MIMES,
            'max:'.self::MAX_KILOBYTES,
        ];
    }

    public static function messages(): array
    {
        return [
            'attachments.*.max' => "File can't be larger than ".self::MAX_LABEL.'.',
            'attachments.*.mimes' => 'Unsupported file type. Allowed: images, PDF, Word, Excel, TXT, CSV and ZIP.',
            'attachments.*.file' => 'The attachment must be a valid file.',
            'attachment.max' => "File can't be larger than ".self::MAX_LABEL.'.',
            'attachment.mimes' => 'Unsupported file type. Allowed: images, PDF, Word, Excel, TXT, CSV and ZIP.',
        ];
    }

    public static function isImage(string $mime): bool
    {
        return str_starts_with($mime, 'image/');
    }

    /** Never trust the client-supplied filename: keep a safe basename. */
    public static function safeFilename(string $original): string
    {
        $original = basename(str_replace('\\', '/', $original));

        return preg_replace('/[^A-Za-z0-9._-]/', '_', $original) ?: 'file';
    }
}
