<?php

namespace App\Http\Controllers;

use App\Models\MessageAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /** Inspect the live adapter so Storage::fake() tests can stream locally. */
    private function isLocalDisk(): bool
    {
        return Storage::disk('minio')->getAdapter() instanceof LocalFilesystemAdapter;
    }

    /** Authorize first, then hand out a short-lived signed MinIO URL. */
    public function download(MessageAttachment $attachment): RedirectResponse|StreamedResponse
    {
        $this->authorize('view', $attachment);

        $disk = Storage::disk('minio');
        $path = $attachment->storage_path;
        abort_unless($disk->exists($path), 404);

        $disposition = 'attachment; filename="'.$attachment->safeName().'"';

        if (! $this->isLocalDisk()) {
            // Sign against the browser-visible endpoint, not the internal
            // Docker hostname used for server-side storage operations.
            $url = Storage::disk('minio-public')->temporaryUrl($path, now()->addMinutes(5), [
                'ResponseContentDisposition' => $disposition,
                'ResponseContentType' => $attachment->mime_type,
            ]);

            return redirect()->away($url);
        }

        // Storage::fake() in tests uses the local driver, which cannot sign.
        return response()->streamDownload(function () use ($disk, $path) {
            echo $disk->get($path);
        }, $attachment->original_filename, ['Content-Type' => $attachment->mime_type]);
    }

    /** Inline preview for images only. */
    public function preview(MessageAttachment $attachment): RedirectResponse|Response|StreamedResponse
    {
        $this->authorize('view', $attachment);
        abort_unless($attachment->attachment_type === 'image', 404);

        $disk = Storage::disk('minio');
        $path = $attachment->storage_path;
        abort_unless($disk->exists($path), 404);

        if (! $this->isLocalDisk()) {
            return redirect()->away(Storage::disk('minio-public')->temporaryUrl($path, now()->addMinutes(5), [
                'ResponseContentDisposition' => 'inline',
                'ResponseContentType' => $attachment->mime_type,
            ]));
        }

        return response($disk->get($path), 200)
            ->header('Content-Type', $attachment->mime_type);
    }
}
