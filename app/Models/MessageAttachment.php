<?php

namespace App\Models;

use App\Support\AttachmentUpload;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id', 'original_filename', 'storage_path',
        'mime_type', 'file_size', 'attachment_type',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function safeName(): string
    {
        return AttachmentUpload::safeFilename($this->original_filename);
    }

    public function sizeLabel(): string
    {
        $bytes = (int) $this->file_size;

        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1).' MB';
        }

        return max(1, (int) round($bytes / 1024)).' KB';
    }

    public function payload(): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_size' => (int) $this->file_size,
            'size_label' => $this->sizeLabel(),
            'attachment_type' => $this->attachment_type,
            'download_url' => route('attachments.download', $this->id),
            'preview_url' => route('attachments.preview', $this->id),
        ];
    }
}
