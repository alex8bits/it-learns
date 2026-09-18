<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\ConvertCoursePreviewToWebp;
use App\Models\Course;
use App\Services\Courses\PreviewImageProcessor;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ConvertCoursePreviewToWebpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_converts_the_preview_updates_the_path_and_deletes_the_source(): void
    {
        Storage::disk('public')->put('courses/previews/job.jpg', $this->jpegBytes(300, 200));
        $course = Course::factory()->create(['preview_image_path' => 'courses/previews/job.jpg']);

        (new ConvertCoursePreviewToWebp($course->id))->handle(new PreviewImageProcessor);

        $this->assertSame('courses/previews/job.webp', $course->refresh()->preview_image_path);
        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'preview_image_path' => 'courses/previews/job.webp',
        ]);

        Storage::disk('public')->assertMissing('courses/previews/job.jpg');
        Storage::disk('public')->assertExists('courses/previews/job.webp');

        $info = getimagesize(Storage::disk('public')->path('courses/previews/job.webp'));
        assert($info !== false);
        $this->assertSame('image/webp', $info['mime']);
        $this->assertSame(300, $info[0]);
        $this->assertSame(200, $info[1]);
    }

    public function test_is_a_noop_when_the_course_no_longer_exists(): void
    {
        Storage::disk('public')->put('courses/previews/orphan.jpg', $this->jpegBytes(50, 50));

        (new ConvertCoursePreviewToWebp(999999))->handle(new PreviewImageProcessor);

        Storage::disk('public')->assertExists('courses/previews/orphan.jpg');
    }

    public function test_is_a_noop_when_the_preview_path_is_null(): void
    {
        $course = Course::factory()->create(['preview_image_path' => null]);

        (new ConvertCoursePreviewToWebp($course->id))->handle(new PreviewImageProcessor);

        $this->assertNull($course->refresh()->preview_image_path);
    }

    public function test_is_a_noop_when_the_preview_is_already_webp(): void
    {
        Storage::disk('public')->put('courses/previews/done.webp', $this->webpBytes(80, 60));
        $course = Course::factory()->create(['preview_image_path' => 'courses/previews/done.webp']);

        (new ConvertCoursePreviewToWebp($course->id))->handle(new PreviewImageProcessor);

        $this->assertSame('courses/previews/done.webp', $course->refresh()->preview_image_path);
        Storage::disk('public')->assertExists('courses/previews/done.webp');
        $this->assertCount(1, Storage::disk('public')->files('courses/previews'));
    }

    public function test_a_null_conversion_leaves_the_stored_path_untouched(): void
    {
        $course = Course::factory()->create(['preview_image_path' => 'courses/previews/keep.jpg']);

        $previews = Mockery::mock(PreviewImageProcessor::class);
        $previews->shouldReceive('convertToWebp')
            ->once()
            ->with('courses/previews/keep.jpg')
            ->andReturn(null);
        $previews->shouldNotReceive('delete');

        $this->instance(PreviewImageProcessor::class, $previews);

        (new ConvertCoursePreviewToWebp($course->id))->handle(app(PreviewImageProcessor::class));

        $this->assertSame('courses/previews/keep.jpg', $course->refresh()->preview_image_path);
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
    private function webpBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 60, 100, 220));
        ob_start();
        imagewebp($image, null, 82);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
