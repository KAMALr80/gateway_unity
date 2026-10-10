<?php

namespace App\Services;

use App\Models\Media;
use GdImage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * WebP variants of remote product images.
 *
 * Variant URLs are deterministic (media id + hash of the source URL + size), so building them costs nothing
 * and a changed source URL gives new URLs, which makes the files safe to cache as immutable. The first
 * request for a source downloads it once and writes every size; later requests read the stored files.
 * Originals are never modified or deleted.
 */
class MediaThumbnails
{
    private const ALLOWED_MIME = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /** Variant URLs keyed by size, plus the untouched original, or null when the image has no variants. */
    public function variantsFor(?Media $media): ?array
    {
        if (! $media || ! $this->eligible($media->public_url)) {
            return null;
        }

        $key = $this->key($media->public_url);
        $variants = [];
        foreach ($this->sizes() as $size) {
            $variants[(string) $size] = url("/api/media/{$media->id}/{$key}/{$size}.webp");
        }
        $variants['original'] = $media->public_url;

        return $variants;
    }

    public function sizes(): array
    {
        return config('media.thumbnails.sizes');
    }

    public function key(string $sourceUrl): string
    {
        return substr(hash('sha256', $sourceUrl), 0, 20);
    }

    /** Only https sources on the configured hosts are ever fetched (no arbitrary URL fetching). */
    public function eligible(?string $sourceUrl): bool
    {
        if (! config('media.thumbnails.enabled') || ! $sourceUrl) {
            return false;
        }

        $parts = parse_url($sourceUrl);
        if (($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        return in_array(strtolower($parts['host'] ?? ''), config('media.thumbnails.source_hosts'), true);
    }

    public function path(string $key, int $size): string
    {
        return config('media.thumbnails.directory')."/{$key}/{$size}.webp";
    }

    /**
     * Absolute path of the stored variant, generating all sizes on first use.
     * Null when the variant cannot be produced right now; callers fall back to the original.
     */
    public function resolve(Media $media, int $size): ?string
    {
        $source = $media->public_url;
        if (! in_array($size, $this->sizes(), true) || ! $this->eligible($source)) {
            return null;
        }

        $key = $this->key($source);
        $disk = Storage::disk('local');
        if ($disk->exists($this->path($key, $size))) {
            return $disk->path($this->path($key, $size));
        }

        if (Cache::has("media-thumb-failed:{$key}") || ! $this->canEncode()) {
            return null;
        }

        // One download per source even when the optimizer asks for several sizes at once: concurrent
        // requests wait for the first one, then read the files it wrote.
        $lock = Cache::lock("media-thumb:{$key}", 60);
        try {
            $lock->block(25);
        } catch (LockTimeoutException) {
            return null;
        }

        try {
            if (! $disk->exists($this->path($key, $size)) && ! Cache::has("media-thumb-failed:{$key}")) {
                $this->generate($source, $key);
            }
        } catch (\Throwable $e) {
            Cache::put("media-thumb-failed:{$key}", true, (int) config('media.thumbnails.failure_ttl'));
            Log::warning('Image variant generation failed', ['media_id' => $media->id, 'error' => $e->getMessage()]);

            return null;
        } finally {
            $lock->release();
        }

        return $disk->exists($this->path($key, $size)) ? $disk->path($this->path($key, $size)) : null;
    }

    public function canEncode(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagewebp');
    }

    private function generate(string $source, string $key): void
    {
        $image = $this->decode($this->download($source));
        $disk = Storage::disk('local');

        foreach ($this->sizes() as $size) {
            $disk->put($this->path($key, $size), $this->encode($image, $size));
        }
    }

    private function download(string $source): string
    {
        $maxBytes = (int) config('media.thumbnails.max_source_bytes');

        $response = Http::connectTimeout((int) config('media.thumbnails.connect_timeout'))
            ->timeout((int) config('media.thumbnails.timeout'))
            ->withOptions([
                'allow_redirects' => false,
                'on_headers' => function ($response) use ($maxBytes) {
                    if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                        throw new RuntimeException('Source image is too large.');
                    }
                },
            ])
            ->accept('image/*')
            ->get($source);

        if (! $response->successful()) {
            throw new RuntimeException("Source responded with HTTP {$response->status()}.");
        }

        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            throw new RuntimeException("Source is not a supported image ({$mime}).");
        }

        $body = $response->body();
        if (strlen($body) > $maxBytes) {
            throw new RuntimeException('Source image is too large.');
        }

        return $body;
    }

    private function decode(string $body): GdImage
    {
        $info = @getimagesizefromstring($body);
        if (! $info || ! in_array($info['mime'] ?? '', self::ALLOWED_MIME, true)) {
            throw new RuntimeException('Source is not a decodable image.');
        }
        if ($info[0] < 1 || $info[1] < 1 || (int) config('media.thumbnails.max_source_pixels') < $info[0] * $info[1]) {
            throw new RuntimeException('Source image dimensions are out of range.');
        }

        $image = @imagecreatefromstring($body);
        if (! $image instanceof GdImage) {
            throw new RuntimeException('Source image could not be decoded.');
        }

        return $image;
    }

    /** Fit inside size×size keeping the aspect ratio and transparency; never upscale. */
    private function encode(GdImage $source, int $size): string
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $size / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        $ok = imagewebp($target, null, (int) config('media.thumbnails.quality'));
        $data = (string) ob_get_clean();

        if (! $ok || $data === '') {
            throw new RuntimeException('WebP encoding failed.');
        }

        return $data;
    }
}
