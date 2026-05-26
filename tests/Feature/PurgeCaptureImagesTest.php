<?php

namespace Tests\Feature;

use App\Models\Capture;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeCaptureImagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_purges_images_after_retention_window(): void
    {
        Storage::fake('local');
        config(['services.capture_retention_days' => 30]);

        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        Storage::disk('local')->put('captures/old.jpg', 'image-bytes');

        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_REVIEWED,
            'image_path' => 'captures/old.jpg',
        ]);
        $capture->forceFill([
            'created_at' => now()->subDays(31),
            'updated_at' => now()->subDays(31),
        ])->save();

        $this->artisan('captures:purge-images')->assertSuccessful();

        $capture->refresh();
        $this->assertNull($capture->image_path);
        $this->assertNotNull($capture->image_purged_at);
        Storage::disk('local')->assertMissing('captures/old.jpg');
    }
}
