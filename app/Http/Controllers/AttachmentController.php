<?php

namespace App\Http\Controllers;

use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function download(MessageAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $attachment);

        $disk = Storage::disk('minio');
        $path = $attachment->storage_path;
        abort_unless($disk->exists($path), 404);

        $disposition = 'attachment; filename="'.$attachment->safeName().'"';

        return $this->stream($disk, $path, $disposition, $attachment->mime_type);
    }

    public function preview(MessageAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $attachment);
        abort_unless($attachment->attachment_type === 'image', 404);

        $disk = Storage::disk('minio');
        $path = $attachment->storage_path;
        abort_unless($disk->exists($path), 404);

        return $this->stream($disk, $path, 'inline', $attachment->mime_type);
    }

    private function stream($disk, string $path, string $disposition, string $mimeType): StreamedResponse
    {
        return response()->stream(function () use ($disk, $path) {
            $stream = $disk->readStream($path);

            if (! is_resource($stream)) {
                return;
            }

            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => $disposition,
            'Content-Length' => $disk->size($path),
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
