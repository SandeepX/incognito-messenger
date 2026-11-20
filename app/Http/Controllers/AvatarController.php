<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class AvatarController extends Controller
{
    public function show(string $filename): Response
    {
        $disk = Storage::disk('minio');
        $path = 'avatars/'.basename($filename);

        abort_unless($disk->exists($path), 404);

        return response($disk->get($path), 200)
            ->header('Content-Type', $disk->mimeType($path) ?: 'application/octet-stream')
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
