<?php

declare(strict_types=1);

namespace App\Services\Courses;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Synchronous GD preview-image processor (Stage 6 design, decision #4).
 *
 * A valid image is fit-scaled (never upscaled) into a 1200x630 box with
 * the aspect ratio preserved and re-encoded in its original format —
 * the re-encode drops EXIF metadata. When the running PHP build lacks
 * the GD codec for the format (e.g. no WebP support), the original
 * bytes are stored untouched with a warning instead of failing.
 *
 * Stage 11 adds `convertToWebp()`: a queued post-commit conversion of
 * an already-stored preview into WebP with the same null-degrading
 * semantics — the preview stays available in its original format
 * whenever the conversion cannot run.
 */
class PreviewImageProcessor
{
    private const int MAX_WIDTH = 1200;

    private const int MAX_HEIGHT = 630;

    /**
     * Mime types `convertToWebp()` accepts as conversion sources; the
     * sniffed mime (never the file extension) decides.
     */
    private const array CONVERTIBLE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Validate, resize and store the uploaded preview; returns the path
     * relative to the `public` disk (`courses/previews/{uuid}.{ext}`).
     *
     * Corrupt images fail loudly with a RuntimeException; a missing GD
     * codec degrades to storing the original file as-is.
     *
     * @throws RuntimeException
     */
    public function process(UploadedFile $file): string
    {
        $sourcePath = $file->getRealPath();

        if ($sourcePath === false) {
            throw new RuntimeException('The uploaded preview file is no longer available.');
        }

        $info = @getimagesize($sourcePath);

        if ($info === false) {
            throw new RuntimeException('The preview file is not a valid image.');
        }

        $mime = (string) $info['mime'];

        if (! $this->isCodecAvailable($mime)) {
            return $this->storeOriginal($file, $mime);
        }

        $source = $this->decode($mime, $sourcePath);

        $resized = $this->fitWithin($source);

        try {
            $binary = $this->encode($mime, $resized);
        } finally {
            imagedestroy($resized);
        }

        imagedestroy($source);

        $path = 'courses/previews/'.Str::uuid()->toString().'.'.$this->extensionFor($mime);
        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    /**
     * Remove a preview file from the `public` disk. A null/empty path
     * and a missing file are no-ops; disk errors are logged instead of
     * thrown — the method runs after the database transaction has
     * committed and must not fail the request.
     */
    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        try {
            $disk = Storage::disk('public');

            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        } catch (Throwable $exception) {
            Log::warning('courses.preview_processor_delete_failed', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Convert an already-stored preview to WebP (Stage 11: queued
     * post-commit conversion). Reads the file from the `public` disk,
     * re-encodes it via GD (quality 82, the same constant as the webp
     * branch of encode()) and stores it next to the source under the
     * same uuid stem. Returns the new path, or null when the conversion
     * is not applicable — and never throws: the caller is a queued job,
     * and a degrading no-op is preferable to a crash-looping job.
     *
     * No resizing here: the file is already within the 1200x630 box
     * after the synchronous process().
     */
    public function convertToWebp(string $path): ?string
    {
        if (Str::endsWith($path, '.webp')) {
            return null;
        }

        try {
            $disk = Storage::disk('public');

            if (! $disk->exists($path)) {
                return null;
            }

            $binary = (string) $disk->get($path);

            $info = @getimagesizefromstring($binary);

            if ($info === false || ! in_array((string) $info['mime'], self::CONVERTIBLE_MIMES, true)) {
                Log::warning('courses.preview_processor_skipped', [
                    'path' => $path,
                    'reason' => 'unsupported_mime',
                ]);

                return null;
            }

            $mime = (string) $info['mime'];

            if (! $this->isCodecAvailable($mime) || ! $this->isCodecAvailable('image/webp')) {
                Log::warning('courses.preview_processor_skipped', [
                    'path' => $path,
                    'reason' => 'gd_codec_unavailable',
                ]);

                return null;
            }

            $webp = $this->reencodeAsWebp($mime, $binary);

            $newPath = Str::beforeLast($path, '.').'.webp';
            $disk->put($newPath, $webp);

            return $newPath;
        } catch (Throwable $exception) {
            Log::warning('courses.preview_processor_skipped', [
                'path' => $path,
                'reason' => 'conversion_failed',
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Whether the running PHP build ships the GD read/write functions
     * for the mime type. Protected so unit tests can simulate a build
     * compiled without a specific codec.
     */
    protected function isCodecAvailable(string $mime): bool
    {
        return match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') && function_exists('imagejpeg'),
            'image/png' => function_exists('imagecreatefrompng') && function_exists('imagepng'),
            'image/webp' => function_exists('imagecreatefromwebp') && function_exists('imagewebp'),
            default => false,
        };
    }

    /**
     * @throws RuntimeException
     */
    private function decode(string $mime, string $path): GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };

        if ($image === false) {
            throw new RuntimeException("Failed to decode the preview image ({$mime}).");
        }

        return $image;
    }

    /**
     * Fit the image into the 1200x630 box preserving the aspect ratio;
     * smaller images are kept as-is (never upscaled).
     *
     * @throws RuntimeException
     */
    private function fitWithin(GdImage $source): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);

        $ratio = min(self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height, 1.0);

        $targetWidth = max(1, (int) floor($width * $ratio));
        $targetHeight = max(1, (int) floor($height * $ratio));

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($resized === false) {
            throw new RuntimeException('Failed to allocate the resized preview canvas.');
        }

        // Alpha-preserving copy (harmless for opaque sources; keeps
        // transparency of PNG/WebP previews through the re-encode).
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);

        if ($transparent !== false) {
            imagefill($resized, 0, 0, $transparent);
        }

        if (! imagecopyresampled($resized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
            imagedestroy($resized);
            throw new RuntimeException('Failed to resize the preview image.');
        }

        return $resized;
    }

    /**
     * Re-encode into the original format; the round-trip through GD
     * pixels strips EXIF metadata.
     *
     * @throws RuntimeException
     */
    private function encode(string $mime, GdImage $image): string
    {
        ob_start();

        $encoded = match ($mime) {
            'image/jpeg' => imagejpeg($image, null, 82),
            'image/png' => imagepng($image, null, 6),
            'image/webp' => imagewebp($image, null, 82),
            default => false,
        };

        $binary = ob_get_clean();

        if ($encoded === false || $binary === false) {
            throw new RuntimeException("Failed to encode the preview image as {$mime}.");
        }

        return $binary;
    }

    /**
     * Store the uploaded bytes untouched (GD codec missing) and warn —
     * the file is already validated upstream, refusing to store it
     * would break the whole course operation. The stored extension is
     * derived from the sniffed mime type only: the client-supplied
     * filename extension is never trusted, so a hostile name like
     * `shell.php` cannot place an executable file on the public disk.
     */
    private function storeOriginal(UploadedFile $file, string $mime): string
    {
        Log::warning('courses.preview_processor_skipped', [
            'mime' => $mime,
            'reason' => 'gd_codec_unavailable',
        ]);

        $path = 'courses/previews/'.Str::uuid()->toString().'.'.$this->extensionFor($mime);
        Storage::disk('public')->put($path, $file->getContent());

        return $path;
    }

    /**
     * Decode the stored bytes through the format-routed decode()
     * (GD source functions need a real file, so the binary is buffered
     * to a temporary file) and re-encode them as WebP via encode().
     *
     * @throws RuntimeException
     */
    private function reencodeAsWebp(string $mime, string $binary): string
    {
        $temporaryPath = $this->writeTemporaryFile($binary);
        $source = null;

        try {
            $source = $this->decode($mime, $temporaryPath);

            return $this->encode('image/webp', $source);
        } finally {
            if ($source instanceof GdImage) {
                imagedestroy($source);
            }

            @unlink($temporaryPath);
        }
    }

    /**
     * Buffer the stored bytes to a temporary file GD can read from.
     *
     * @throws RuntimeException
     */
    private function writeTemporaryFile(string $binary): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'it-learns-preview');

        if ($temporaryPath === false || file_put_contents($temporaryPath, $binary) === false) {
            if ($temporaryPath !== false) {
                @unlink($temporaryPath);
            }

            throw new RuntimeException('Failed to buffer the preview image for the WebP conversion.');
        }

        return $temporaryPath;
    }

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'img',
        };
    }
}
