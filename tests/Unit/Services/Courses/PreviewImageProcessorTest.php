<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Courses;

use App\Services\Courses\PreviewImageProcessor;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PreviewImageProcessorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_stores_a_small_jpeg_without_upscaling(): void
    {
        $path = $this->processor()->process(UploadedFile::fake()->image('p.jpg', 300, 300));

        Storage::disk('public')->assertExists($path);
        $this->assertMatchesRegularExpression('#^courses/previews/[0-9a-f-]{36}\.jpg$#', $path);

        [$width, $height] = $this->imageSize($path);
        $this->assertSame(300, $width);
        $this->assertSame(300, $height);
    }

    public function test_fit_scales_a_large_png_into_the_1200x630_box(): void
    {
        $path = $this->processor()->process(UploadedFile::fake()->image('p.png', 2000, 1000));

        [$width, $height] = $this->imageSize($path);

        $this->assertSame(1200, $width);
        $this->assertSame(600, $height);
        $this->assertLessThanOrEqual(1200, $width);
        $this->assertLessThanOrEqual(630, $height);
    }

    public function test_fit_scales_by_the_more_restrictive_dimension(): void
    {
        // 800x2000 is height-bound: 630/2000 < 1200/800.
        $path = $this->processor()->process(UploadedFile::fake()->image('tall.png', 800, 2000));

        [$width, $height] = $this->imageSize($path);

        $this->assertSame(252, $width);
        $this->assertSame(630, $height);
    }

    public function test_reencodes_webp(): void
    {
        $path = $this->processor()->process(UploadedFile::fake()->image('p.webp', 500, 400));

        Storage::disk('public')->assertExists($path);
        $this->assertMatchesRegularExpression('#^courses/previews/[0-9a-f-]{36}\.webp$#', $path);

        [$width, $height] = $this->imageSize($path);
        $this->assertSame(500, $width);
        $this->assertSame(400, $height);
    }

    public function test_corrupt_file_throws_runtime_exception(): void
    {
        $file = UploadedFile::fake()->create('broken.jpg', 1, 'image/jpeg');

        $this->expectException(RuntimeException::class);

        $this->processor()->process($file);
    }

    public function test_missing_gd_codec_stores_original_bytes_with_a_warning(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('courses.preview_processor_skipped', [
                'mime' => 'image/jpeg',
                'reason' => 'gd_codec_unavailable',
            ]);

        $processor = new class extends PreviewImageProcessor
        {
            protected function isCodecAvailable(string $mime): bool
            {
                return false;
            }
        };

        $file = UploadedFile::fake()->image('p.jpg', 300, 300);
        $path = $processor->process($file);

        Storage::disk('public')->assertExists($path);
        $this->assertSame(
            $file->getContent(),
            Storage::disk('public')->get($path),
        );
    }

    public function test_missing_gd_codec_never_uses_the_client_supplied_extension(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('courses.preview_processor_skipped', [
                'mime' => 'image/webp',
                'reason' => 'gd_codec_unavailable',
            ]);

        $processor = new class extends PreviewImageProcessor
        {
            protected function isCodecAvailable(string $mime): bool
            {
                return false;
            }
        };

        // Valid WebP bytes under a hostile client-supplied name: the
        // stored extension must come from the sniffed mime type.
        $webp = UploadedFile::fake()->image('p.webp', 300, 300);
        $file = UploadedFile::fake()->createWithContent('evil.php', $webp->getContent());

        $path = $processor->process($file);

        $this->assertMatchesRegularExpression('#^courses/previews/[0-9a-f-]{36}\.webp$#', $path);
        $this->assertStringEndsNotWith('.php', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertSame(
            $webp->getContent(),
            Storage::disk('public')->get($path),
        );
    }

    public function test_delete_removes_the_file_from_the_public_disk(): void
    {
        Storage::disk('public')->put('courses/previews/old.jpg', 'bytes');

        $this->processor()->delete('courses/previews/old.jpg');

        Storage::disk('public')->assertMissing('courses/previews/old.jpg');
    }

    public function test_delete_treats_null_path_as_a_noop(): void
    {
        $this->processor()->delete(null);

        $this->expectNotToPerformAssertions();
    }

    public function test_delete_treats_a_missing_file_as_a_noop(): void
    {
        $this->processor()->delete('courses/previews/never-existed.jpg');

        $this->expectNotToPerformAssertions();
    }

    public function test_delete_logs_a_warning_and_swallows_disk_errors(): void
    {
        $path = 'courses/previews/unreachable.jpg';

        Log::shouldReceive('warning')
            ->once()
            ->with('courses.preview_processor_delete_failed', Mockery::on(function (array $context) use ($path): bool {
                return $context['path'] === $path
                    && str_contains($context['error'], 'disk on fire');
            }));

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->once()->with($path)->andReturn(true);
        $disk->shouldReceive('delete')->once()->with($path)->andThrow(new RuntimeException('disk on fire'));

        Storage::shouldReceive('disk')->once()->with('public')->andReturn($disk);

        $this->processor()->delete($path);
    }

    public function test_convert_to_webp_reencodes_a_stored_jpeg(): void
    {
        $path = $this->processor()->process(UploadedFile::fake()->image('p.jpg', 320, 240));

        $newPath = $this->processor()->convertToWebp($path);

        $this->assertNotNull($newPath);
        $this->assertStringEndsWith('.webp', $newPath);
        $this->assertSame(
            pathinfo($path, PATHINFO_FILENAME),
            pathinfo((string) $newPath, PATHINFO_FILENAME),
        );

        // The source file stays on disk until the queued job deletes it.
        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertExists($newPath);

        $info = getimagesize(Storage::disk('public')->path((string) $newPath));
        assert($info !== false);

        $this->assertSame('image/webp', $info['mime']);
        $this->assertSame(320, $info[0]);
        $this->assertSame(240, $info[1]);
    }

    public function test_convert_to_webp_preserves_png_alpha(): void
    {
        $image = imagecreatetruecolor(120, 80);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 30, 20, 90, 60, (int) imagecolorallocatealpha($image, 255, 0, 0, 0));
        ob_start();
        imagepng($image, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('public')->put('courses/previews/alpha.png', $png);

        $newPath = $this->processor()->convertToWebp('courses/previews/alpha.png');

        $this->assertSame('courses/previews/alpha.webp', $newPath);

        $webpPath = Storage::disk('public')->path('courses/previews/alpha.webp');
        $info = getimagesize($webpPath);
        assert($info !== false);
        $this->assertSame('image/webp', $info['mime']);
        $this->assertSame(120, $info[0]);
        $this->assertSame(80, $info[1]);

        $webp = imagecreatefromwebp($webpPath);
        $this->assertNotFalse($webp);

        $corner = imagecolorsforindex($webp, (int) imagecolorat($webp, 5, 5));
        $center = imagecolorsforindex($webp, (int) imagecolorat($webp, 60, 40));
        imagedestroy($webp);

        // The transparent corner survives the lossy WebP encode (the
        // alpha plane is coded separately); the opaque center stays so.
        $this->assertGreaterThan(100, $corner['alpha']);
        $this->assertLessThanOrEqual(10, $center['alpha']);
    }

    public function test_convert_to_webp_is_a_noop_for_an_already_webp_path(): void
    {
        $path = $this->processor()->process(UploadedFile::fake()->image('p.webp', 100, 100));

        $this->assertNull($this->processor()->convertToWebp($path));

        Storage::disk('public')->assertExists($path);
        $this->assertCount(1, Storage::disk('public')->files('courses/previews'));
    }

    public function test_convert_to_webp_is_a_noop_for_a_missing_file(): void
    {
        $this->assertNull($this->processor()->convertToWebp('courses/previews/never-existed.jpg'));
    }

    public function test_convert_to_webp_skips_an_unsupported_mime(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('courses.preview_processor_skipped', [
                'path' => 'courses/previews/pic.gif',
                'reason' => 'unsupported_mime',
            ]);

        $image = imagecreatetruecolor(50, 50);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 0, 128, 255));
        ob_start();
        imagegif($image, null);
        $gif = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('public')->put('courses/previews/pic.gif', $gif);

        $this->assertNull($this->processor()->convertToWebp('courses/previews/pic.gif'));
        Storage::disk('public')->assertExists('courses/previews/pic.gif');
    }

    public function test_convert_to_webp_with_a_corrupt_file_degrades_to_null(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('courses.preview_processor_skipped', [
                'path' => 'courses/previews/broken.jpg',
                'reason' => 'unsupported_mime',
            ]);

        Storage::disk('public')->put('courses/previews/broken.jpg', 'not-an-image');

        $this->assertNull($this->processor()->convertToWebp('courses/previews/broken.jpg'));
        Storage::disk('public')->assertExists('courses/previews/broken.jpg');
    }

    public function test_convert_to_webp_without_the_webp_codec_degrades_to_null(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('courses.preview_processor_skipped', [
                'path' => 'courses/previews/codec.jpg',
                'reason' => 'gd_codec_unavailable',
            ]);

        $processor = new class extends PreviewImageProcessor
        {
            protected function isCodecAvailable(string $mime): bool
            {
                return $mime !== 'image/webp';
            }
        };

        Storage::disk('public')->put('courses/previews/codec.jpg', $this->jpegBytes(60, 40));

        $this->assertNull($processor->convertToWebp('courses/previews/codec.jpg'));
        Storage::disk('public')->assertExists('courses/previews/codec.jpg');
    }

    public function test_convert_to_webp_with_an_undecodable_file_warns_and_returns_null(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('courses.preview_processor_skipped', Mockery::on(function (array $context): bool {
                return $context['path'] === 'courses/previews/truncated.png'
                    && $context['reason'] === 'conversion_failed';
            }));

        // A valid PNG header (sniffable dimensions) without the IDAT
        // and IEND chunks: the mime sniff succeeds while the GD decode
        // of the truncated stream fails.
        Storage::disk('public')->put('courses/previews/truncated.png', substr($this->pngBytes(40, 40), 0, 33));

        $this->assertNull($this->processor()->convertToWebp('courses/previews/truncated.png'));
        Storage::disk('public')->assertExists('courses/previews/truncated.png');
    }

    private function processor(): PreviewImageProcessor
    {
        return app(PreviewImageProcessor::class);
    }

    /**
     * @param  int<1, max>  $width
     * @param  int<1, max>  $height
     */
    private function jpegBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 120, 40));
        ob_start();
        imagejpeg($image, null, 82);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * @param  int<1, max>  $width
     * @param  int<1, max>  $height
     */
    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 20, 160, 90));
        ob_start();
        imagepng($image, null, 6);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function imageSize(string $path): array
    {
        $size = getimagesize(Storage::disk('public')->path($path));
        assert($size !== false);

        return $size;
    }
}
