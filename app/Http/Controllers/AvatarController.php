<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AvatarController extends Controller
{
    /**
     * Avatars live in private storage; this route is the only public door.
     * The route pattern already restricts the filename, and basename()
     * protects against path traversal if this method is reused elsewhere.
     */
    public function show(string $filename): BinaryFileResponse
    {
        $path = 'avatars/'.basename($filename);

        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => Storage::disk('local')->mimeType($path),
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
