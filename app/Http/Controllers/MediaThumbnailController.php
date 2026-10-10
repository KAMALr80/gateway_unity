<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Services\MediaThumbnails;
use Symfony\Component\HttpFoundation\Response;

class MediaThumbnailController extends Controller
{
    public function __construct(protected MediaThumbnails $thumbnails) {}

    public function show(int $id, string $hash, int $size): Response
    {
        $media = Media::find($id);

        // The hash ties the URL to one exact source URL: a re-synced image gets a new URL, an old or
        // guessed URL is a 404, and only images the API itself advertised can be requested.
        abort_unless(
            $media
            && in_array($size, $this->thumbnails->sizes(), true)
            && $this->thumbnails->eligible($media->public_url)
            && hash_equals($this->thumbnails->key($media->public_url), $hash),
            404
        );

        $path = $this->thumbnails->resolve($media, $size);

        if ($path === null) {
            // Variant not available (source down, generation in progress, no WebP support): use the original.
            return redirect()->away($media->public_url, 302, ['Cache-Control' => 'public, max-age=300']);
        }

        return response()->file($path, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ])->setAutoEtag();
    }
}
