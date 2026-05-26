<?php

namespace Tests\Unit;

use App\Services\CaptureImageNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CaptureImageNormalizerTest extends TestCase
{
    public function test_it_converts_uploaded_images_to_jpeg_files(): void
    {
        Storage::fake('local');

        $result = (new CaptureImageNormalizer)->normalize(
            UploadedFile::fake()->image('badge.png', 1200, 900),
        );

        Storage::disk('local')->assertExists($result['path']);
        $this->assertStringEndsWith('.jpg', $result['path']);
        $this->assertSame('badge-lead-capture.jpg', $result['filename']);
        $this->assertStringStartsWith("\xff\xd8", Storage::disk('local')->get($result['path']));
    }
}
