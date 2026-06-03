<?php

namespace Tests\Feature;

use App\Jobs\FindPublicEmailForCapture;
use App\Jobs\ProcessCaptureImage;
use App\Models\Capture;
use App\Models\District;
use App\Models\Event;
use App\Models\User;
use App\Services\CaptureImageNormalizer;
use App\Services\DistrictMatcher;
use App\Services\HubSpotClient;
use App\Services\OpenAiLeadExtractor;
use App\Services\PublicLeadEnricher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CaptureFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_upload_queues_single_legacy_photo_for_processing(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);

        $response = $this->actingAs($user)->post('/captures', [
            'event_id' => $event->id,
            'photo' => UploadedFile::fake()->image('badge.jpg', 600, 400),
            'rep_notes' => 'Met at booth.',
        ]);

        $capture = Capture::firstOrFail();
        $response
            ->assertRedirect(route('captures.create'))
            ->assertSessionHas('last_capture_batch_ids', [$capture->id]);

        $this->assertSame(Capture::STATUS_QUEUED, $capture->status);
        $this->assertSame('Met at booth.', $capture->rep_notes);
        Storage::disk('local')->assertExists($capture->image_path);
        Queue::assertPushed(ProcessCaptureImage::class, function (ProcessCaptureImage $job): bool {
            return $job->connection === 'background';
        });
    }

    public function test_capture_upload_queues_multiple_photos_for_processing(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        $event = Event::create(['name' => 'OK Math', 'state_code' => 'OK']);

        $response = $this->actingAs($user)->post('/captures', [
            'event_id' => $event->id,
            'photos' => [
                UploadedFile::fake()->image('badge-1.jpg', 600, 400),
                UploadedFile::fake()->image('badge-2.jpg', 600, 400),
                UploadedFile::fake()->image('badge-3.jpg', 600, 400),
            ],
        ]);

        $captures = Capture::query()->orderBy('id')->get();

        $response
            ->assertRedirect(route('captures.create'))
            ->assertSessionHas('last_capture_batch_ids', $captures->pluck('id')->all());

        $this->assertCount(3, $captures);
        $this->assertSame([Capture::STATUS_QUEUED, Capture::STATUS_QUEUED, Capture::STATUS_QUEUED], $captures->pluck('status')->all());
        Queue::assertPushed(ProcessCaptureImage::class, 3);
        Queue::assertPushed(ProcessCaptureImage::class, function (ProcessCaptureImage $job): bool {
            return $job->connection === 'background';
        });
    }

    public function test_capture_upload_can_append_to_last_batch_for_split_client_uploads(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);

        $firstResponse = $this->actingAs($user)->post('/captures', [
            'event_id' => $event->id,
            'photos' => [
                UploadedFile::fake()->image('badge-1.jpg', 600, 400),
            ],
        ]);

        $firstCapture = Capture::firstOrFail();
        $firstResponse->assertSessionHas('last_capture_batch_ids', [$firstCapture->id]);

        $secondResponse = $this->actingAs($user)
            ->withSession(['last_capture_batch_ids' => [$firstCapture->id]])
            ->post('/captures', [
                'event_id' => $event->id,
                'append_to_last_batch' => '1',
                'photos' => [
                    UploadedFile::fake()->image('badge-2.jpg', 600, 400),
                ],
            ]);

        $captures = Capture::query()->orderBy('id')->get();

        $secondResponse->assertSessionHas('last_capture_batch_ids', $captures->pluck('id')->all());
        $this->assertCount(2, $captures);
        Queue::assertPushed(ProcessCaptureImage::class, 2);
    }

    public function test_processing_job_fills_fields_and_matches_district(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $event = Event::create(['name' => 'OK Math', 'state_code' => 'OK']);
        $district = District::create([
            'state_code' => 'OK',
            'lea_id' => '4015480',
            'name' => 'Putnam City',
            'short_name' => 'Putnam City',
            'city' => 'Oklahoma City',
            'total_students' => 17950,
        ]);
        Storage::disk('local')->put('captures/incoming/badge.jpg', 'image-bytes');
        Storage::disk('local')->put('captures/normalized.jpg', 'normalized-image-bytes');
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_QUEUED,
            'image_path' => 'captures/incoming/badge.jpg',
            'original_filename' => 'badge.jpg',
        ]);

        $this->mock(CaptureImageNormalizer::class, function ($mock): void {
            $mock->shouldReceive('normalizeStored')->once()->andReturn([
                'path' => 'captures/normalized.jpg',
                'filename' => 'badge-lead-capture.jpg',
            ]);
        });
        $this->mock(OpenAiLeadExtractor::class, function ($mock): void {
            $mock->shouldReceive('extract')->once()->with('captures/normalized.jpg')->andReturn([
                'full_name' => 'Jordan Ellis',
                'first_name' => 'Jordan',
                'last_name' => 'Ellis',
                'email' => 'jordan@example.org',
                'phone' => null,
                'title' => 'Instructional Coach',
                'organization' => null,
                'city' => null,
                'state' => 'OK',
                'raw_text' => 'Jordan Ellis Instructional Coach Putnam City Schools',
                'confidence' => ['overall' => 0.87],
                'evidence' => ['Putnam City Schools'],
                'warnings' => [],
                'insights' => [
                    'district_clues' => ['Putnam City Schools'],
                ],
                'ai_confidence' => 0.87,
                'extracted_payload' => [
                    'insights' => [
                        'district_clues' => ['Putnam City Schools'],
                    ],
                ],
            ]);
        });
        $this->mock(HubSpotClient::class, function ($mock): void {
            $mock->shouldReceive('lookupLeadContext')->once()->andReturn([
                'contact' => null,
                'company' => null,
            ]);
        });

        (new ProcessCaptureImage($capture->id))->handle(
            app(CaptureImageNormalizer::class),
            app(OpenAiLeadExtractor::class),
            app(DistrictMatcher::class),
            app(HubSpotClient::class),
        );

        $capture->refresh();
        $this->assertSame(Capture::STATUS_COMPLETE, $capture->status);
        $this->assertSame('Jordan Ellis', $capture->full_name);
        $this->assertSame('jordan@example.org', $capture->email);
        $this->assertTrue($district->is($capture->district));
        $this->assertSame('Badge text closely matches Putnam City.', $capture->match_reason);
        Storage::disk('local')->assertMissing('captures/incoming/badge.jpg');
    }

    public function test_review_page_has_searchable_district_picker_with_native_fallback(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        District::create([
            'state_code' => 'CO',
            'lea_id' => '0802910',
            'name' => 'Cherry Creek SD',
            'short_name' => 'Cherry Creek',
            'city' => 'Greenwood Village',
            'total_students' => 51980,
        ]);
        District::create([
            'state_code' => 'CO',
            'lea_id' => '0803360',
            'name' => 'Denver County 1',
            'short_name' => 'Denver Public Schools',
            'city' => 'Denver',
            'total_students' => 87855,
        ]);
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_COMPLETE,
            'full_name' => 'Alex Rivera',
        ]);

        $this->actingAs($user)
            ->get(route('captures.review', $capture))
            ->assertOk()
            ->assertSee('data-district-picker', false)
            ->assertSee('data-district-search', false)
            ->assertSee('data-district-select', false)
            ->assertSee('data-district-results', false)
            ->assertSee('Start typing district name')
            ->assertSee('Cherry Creek SD')
            ->assertSee('Denver County 1');
    }

    public function test_capture_status_endpoint_returns_only_requested_user_captures(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        $first = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_QUEUED,
        ]);
        $second = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_COMPLETE,
            'full_name' => 'Alex Rivera',
            'email' => 'alex@example.org',
            'organization' => 'Cherry Creek School District',
        ]);
        $other = Capture::create([
            'user_id' => $otherUser->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'full_name' => 'Hidden Person',
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('captures.status', ['ids' => "{$first->id},{$second->id},{$other->id}"]));

        $response
            ->assertOk()
            ->assertJsonCount(2, 'captures')
            ->assertJsonPath('captures.0.id', $first->id)
            ->assertJsonPath('captures.0.status', Capture::STATUS_QUEUED)
            ->assertJsonPath('captures.0.automation_pending', true)
            ->assertJsonPath('captures.0.ready_for_review', false)
            ->assertJsonPath('captures.1.id', $second->id)
            ->assertJsonPath('captures.1.display_name', 'Alex Rivera')
            ->assertJsonPath('captures.1.automation_pending', false)
            ->assertJsonMissing(['id' => $other->id]);
    }

    public function test_capture_status_endpoint_keeps_polling_while_public_email_search_runs(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'full_name' => 'Alex Rivera',
            'organization' => 'Cherry Creek School District',
            'extracted_payload' => [
                'public_enrichment' => [
                    'status' => 'searching',
                    'email' => null,
                    'confidence' => 0,
                    'summary' => 'Public email search is running.',
                    'sources' => [],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->getJson(route('captures.status', ['ids' => (string) $capture->id]))
            ->assertOk()
            ->assertJsonPath('captures.0.ready_for_review', true)
            ->assertJsonPath('captures.0.automation_pending', true)
            ->assertJsonPath('captures.0.public_enrichment_status', 'searching');
    }

    public function test_capture_status_endpoint_clears_last_batch_session_after_batch_is_complete(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_COMPLETE,
            'full_name' => 'Alex Rivera',
            'email' => 'alex@example.org',
            'organization' => 'Cherry Creek School District',
        ]);

        $this->actingAs($user)
            ->withSession([
                'last_capture_batch_ids' => [$capture->id],
            ])
            ->getJson(route('captures.status', ['ids' => (string) $capture->id]))
            ->assertOk()
            ->assertSessionMissing('last_capture_batch_ids')
            ->assertJsonPath('captures.0.status', Capture::STATUS_COMPLETE)
            ->assertJsonPath('captures.0.automation_pending', false);
    }

    public function test_capture_status_endpoint_restarts_queued_capture_processing(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        Storage::disk('local')->put('captures/incoming/badge.jpg', 'image-bytes');
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_QUEUED,
            'image_path' => 'captures/incoming/badge.jpg',
        ]);

        $this->actingAs($user)
            ->getJson(route('captures.status', ['ids' => (string) $capture->id]))
            ->assertOk()
            ->assertJsonPath('captures.0.automation_pending', true);

        Queue::assertPushed(ProcessCaptureImage::class, function (ProcessCaptureImage $job): bool {
            return $job->connection === 'background';
        });
    }

    public function test_capture_status_endpoint_restarts_queued_public_email_search(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_COMPLETE,
            'full_name' => 'Alex Rivera',
            'organization' => 'Cherry Creek School District',
            'raw_text' => 'Alex Rivera Cherry Creek School District',
            'email' => null,
            'extracted_payload' => [],
        ]);

        $this->actingAs($user)
            ->getJson(route('captures.status', ['ids' => (string) $capture->id]))
            ->assertOk()
            ->assertJsonPath('captures.0.automation_pending', true)
            ->assertJsonPath('captures.0.public_enrichment_status', 'queued');

        $this->assertSame('queued', $capture->fresh()->publicEnrichmentStatus());
        Queue::assertPushed(FindPublicEmailForCapture::class, function (FindPublicEmailForCapture $job): bool {
            return $job->connection === 'background';
        });
    }

    public function test_capture_page_shows_last_batch_panel_and_batch_picker(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_PROCESSING,
        ]);

        $this->actingAs($user)
            ->withSession([
                'current_event_id' => $event->id,
                'current_state_code' => 'CO',
                'last_capture_batch_ids' => [$capture->id],
            ])
            ->get(route('captures.create'))
            ->assertOk()
            ->assertSee('data-batch-panel', false)
            ->assertSee('data-capture-row="'.$capture->id.'"', false)
            ->assertSee('name="photos[]"', false)
            ->assertSee('Queue Photos for AI + Email');
    }

    public function test_capture_page_compresses_large_photos_instead_of_rejecting_them_by_size(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);

        $this->actingAs($user)
            ->withSession([
                'current_event_id' => $event->id,
                'current_state_code' => 'CO',
            ])
            ->get(route('captures.create'))
            ->assertOk()
            ->assertSee('Reducing ${formatBytes(file.size)} photo for upload...', false)
            ->assertSee('append_to_last_batch', false)
            ->assertSee('fetch(form.action', false)
            ->assertDontSee('Choose an image under', false);
    }

    public function test_capture_page_hides_last_batch_panel_after_batch_is_complete(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_COMPLETE,
            'full_name' => 'Alex Rivera',
            'organization' => 'Cherry Creek School District',
            'extracted_payload' => [
                'public_enrichment' => [
                    'status' => 'not_found',
                    'email' => null,
                    'confidence' => 0.71,
                    'summary' => 'No directly sourced email was found.',
                    'sources' => [],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->withSession([
                'current_event_id' => $event->id,
                'current_state_code' => 'CO',
                'last_capture_batch_ids' => [$capture->id],
            ])
            ->get(route('captures.create'))
            ->assertOk()
            ->assertSessionMissing('last_capture_batch_ids')
            ->assertDontSee('Last batch')
            ->assertDontSee('data-capture-row="'.$capture->id.'"', false)
            ->assertSee('Queue Photos for AI + Email');
    }

    public function test_event_and_log_pages_show_processing_statuses(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_PROCESSING,
        ]);

        $this->actingAs($user)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('processing')
            ->assertSee('AI is reading this photo');

        $this->actingAs($user)
            ->get(route('captures.index'))
            ->assertOk()
            ->assertSee('processing')
            ->assertSee('AI is reading this photo');
    }

    public function test_processing_job_dispatches_public_email_search_when_email_is_missing(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        Storage::disk('local')->put('captures/incoming/badge.jpg', 'image-bytes');
        Storage::disk('local')->put('captures/normalized.jpg', 'normalized-image-bytes');
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_QUEUED,
            'image_path' => 'captures/incoming/badge.jpg',
            'original_filename' => 'badge.jpg',
        ]);

        $this->mock(CaptureImageNormalizer::class, function ($mock): void {
            $mock->shouldReceive('normalizeStored')->once()->andReturn([
                'path' => 'captures/normalized.jpg',
                'filename' => 'badge-lead-capture.jpg',
            ]);
        });
        $this->mock(OpenAiLeadExtractor::class, function ($mock): void {
            $mock->shouldReceive('extract')->once()->andReturn([
                'full_name' => 'Alex Rivera',
                'first_name' => 'Alex',
                'last_name' => 'Rivera',
                'email' => null,
                'phone' => null,
                'title' => 'Math Director',
                'organization' => 'Cherry Creek School District',
                'city' => null,
                'state' => 'CO',
                'raw_text' => 'Alex Rivera Cherry Creek School District',
                'confidence' => ['overall' => 0.91],
                'evidence' => ['Cherry Creek School District'],
                'warnings' => [],
                'insights' => [],
                'ai_confidence' => 0.91,
                'extracted_payload' => [],
            ]);
        });
        $this->mock(HubSpotClient::class, function ($mock): void {
            $mock->shouldReceive('lookupLeadContext')->once()->andReturn([
                'contact' => null,
                'company' => null,
            ]);
        });

        (new ProcessCaptureImage($capture->id))->handle(
            app(CaptureImageNormalizer::class),
            app(OpenAiLeadExtractor::class),
            app(DistrictMatcher::class),
            app(HubSpotClient::class),
        );

        $capture->refresh();
        $this->assertSame(Capture::STATUS_COMPLETE, $capture->status);
        $this->assertSame('queued', $capture->publicEnrichment()['status']);
        Queue::assertPushed(FindPublicEmailForCapture::class, function (FindPublicEmailForCapture $job): bool {
            return $job->connection === 'background';
        });
    }

    public function test_processing_job_marks_extraction_failure_for_manual_review(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        Storage::disk('local')->put('captures/incoming/badge.jpg', 'image-bytes');
        Storage::disk('local')->put('captures/normalized.jpg', 'normalized-image-bytes');
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_QUEUED,
            'image_path' => 'captures/incoming/badge.jpg',
            'original_filename' => 'badge.jpg',
        ]);

        $this->mock(CaptureImageNormalizer::class, function ($mock): void {
            $mock->shouldReceive('normalizeStored')->once()->andReturn([
                'path' => 'captures/normalized.jpg',
                'filename' => 'badge-lead-capture.jpg',
            ]);
        });
        $this->mock(OpenAiLeadExtractor::class, function ($mock): void {
            $mock->shouldReceive('extract')->once()->andThrow(new RuntimeException('Vision timeout.'));
        });

        (new ProcessCaptureImage($capture->id))->handle(
            app(CaptureImageNormalizer::class),
            app(OpenAiLeadExtractor::class),
            app(DistrictMatcher::class),
            app(HubSpotClient::class),
        );

        $capture->refresh();
        $this->assertSame(Capture::STATUS_EXTRACTION_FAILED, $capture->status);
        $this->assertSame('captures/normalized.jpg', $capture->image_path);
        $this->assertSame('AI extraction failed. Review and enter fields manually.', $capture->sync_error);
    }

    public function test_public_email_job_applies_confident_sourced_email(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'full_name' => 'Alex Rivera',
            'organization' => 'Cherry Creek School District',
            'raw_text' => 'Alex Rivera Cherry Creek School District',
        ]);

        $this->mock(PublicLeadEnricher::class, function ($mock): void {
            $mock->shouldReceive('enrich')->once()->andReturn([
                'status' => 'found',
                'email' => 'alex.rivera@cherrycreekschools.org',
                'confidence' => 0.86,
                'person_match' => 'Name matches a public staff page.',
                'organization_match' => 'Organization matches badge clues.',
                'summary' => 'Email found on official staff directory.',
                'sources' => [[
                    'title' => 'Staff Directory',
                    'url' => 'https://example.org/staff/alex-rivera',
                    'evidence' => 'Lists Alex Rivera email.',
                ]],
                'checked_at' => now()->toIso8601String(),
            ]);
        });

        (new FindPublicEmailForCapture($capture->id))->handle(app(PublicLeadEnricher::class));

        $capture->refresh();
        $this->assertSame('alex.rivera@cherrycreekschools.org', $capture->email);
        $this->assertSame(Capture::STATUS_COMPLETE, $capture->status);
        $this->assertSame('found', $capture->publicEnrichment()['status']);
    }

    public function test_capture_photo_is_required(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);

        $this->actingAs($user)->post('/captures', [
            'event_id' => $event->id,
        ])->assertSessionHasErrors('photo');
    }

    public function test_capture_batch_is_limited_to_twelve_photos(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);

        $this->actingAs($user)->post('/captures', [
            'event_id' => $event->id,
            'photos' => collect(range(1, 13))
                ->map(fn (int $index) => UploadedFile::fake()->image("badge-{$index}.jpg", 600, 400))
                ->all(),
        ])->assertSessionHasErrors('photos');
    }

    public function test_capture_can_be_deleted_from_event_workspace(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        Storage::disk('local')->put('captures/badge.jpg', 'image-bytes');
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'image_path' => 'captures/badge.jpg',
            'full_name' => 'Alex Rivera',
        ]);

        $this->actingAs($user)->delete("/captures/{$capture->id}", [
            'return_to' => 'event',
        ])->assertRedirect(route('events.show', $event));

        $this->assertDatabaseMissing('captures', ['id' => $capture->id]);
        Storage::disk('local')->assertMissing('captures/badge.jpg');
    }

    public function test_capture_delete_defaults_back_to_capture_log(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'full_name' => 'Alex Rivera',
        ]);

        $this->actingAs($user)->delete("/captures/{$capture->id}")
            ->assertRedirect(route('captures.index'));

        $this->assertDatabaseMissing('captures', ['id' => $capture->id]);
    }

    public function test_review_update_and_manual_hubspot_sync(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        $district = District::create([
            'state_code' => 'CO',
            'lea_id' => '0802910',
            'name' => 'Cherry Creek SD',
        ]);
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'full_name' => 'Alex Rivera',
            'email' => 'alex@example.org',
            'organization' => 'Cherry Creek School District',
        ]);

        $this->actingAs($user)->patch("/captures/{$capture->id}", [
            'district_id' => $district->id,
            'full_name' => 'Alex Rivera',
            'first_name' => 'Alex',
            'last_name' => 'Rivera',
            'email' => 'alex@example.org',
            'phone' => '',
            'title' => 'Math Director',
            'organization' => 'Cherry Creek School District',
            'city' => 'Greenwood Village',
            'state' => 'CO',
            'raw_text' => 'Alex Rivera',
            'rep_notes' => 'Follow up next week.',
            'follow_up_status' => 'follow_up',
        ])->assertRedirect(route('captures.review', $capture));

        $this->mock(HubSpotClient::class, function ($mock): void {
            $mock->shouldReceive('syncCapture')->once()->andReturn([
                'contact_id' => '101',
                'company_id' => '202',
                'note_id' => '303',
            ]);
        });

        $this->actingAs($user)->post("/captures/{$capture->id}/hubspot-sync")
            ->assertRedirect(route('captures.review', $capture));

        $capture->refresh();
        $this->assertSame(Capture::STATUS_SYNCED, $capture->status);
        $this->assertSame('101', $capture->hubspot_contact_id);
        $this->assertNotNull($capture->synced_at);
    }

    public function test_reprocess_requires_openai_api_key(): void
    {
        Storage::fake('local');
        config(['services.openai.key' => null]);

        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        Storage::disk('local')->put('captures/badge.jpg', 'image-bytes');
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'image_path' => 'captures/badge.jpg',
        ]);

        $this->actingAs($user)->post("/captures/{$capture->id}/reprocess")
            ->assertRedirect(route('captures.review', $capture))
            ->assertSessionHasErrors('openai');
    }

    public function test_reprocess_refreshes_fields_and_insights_from_stored_image(): void
    {
        Storage::fake('local');
        config(['services.openai.key' => 'test-key']);

        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        $district = District::create([
            'state_code' => 'CO',
            'lea_id' => '0802910',
            'name' => 'Cherry Creek SD',
            'short_name' => 'Cherry Creek',
        ]);
        Storage::disk('local')->put('captures/badge.jpg', 'image-bytes');
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'image_path' => 'captures/badge.jpg',
        ]);

        $this->mock(OpenAiLeadExtractor::class, function ($mock): void {
            $mock->shouldReceive('extract')->once()->andReturn([
                'full_name' => 'Alex Rivera',
                'first_name' => 'Alex',
                'last_name' => 'Rivera',
                'email' => 'alex@cherrycreekschools.org',
                'phone' => null,
                'title' => 'Math Director',
                'organization' => 'Cherry Creek School District',
                'city' => null,
                'state' => 'CO',
                'raw_text' => 'Alex Rivera Math Director Cherry Creek',
                'confidence' => ['overall' => 0.93],
                'evidence' => ['Math Director'],
                'insights' => ['role_category' => 'Curriculum leader'],
                'ai_confidence' => 0.93,
                'extracted_payload' => [
                    'insights' => ['role_category' => 'Curriculum leader'],
                ],
            ]);
        });

        $this->mock(HubSpotClient::class, function ($mock): void {
            $mock->shouldReceive('lookupLeadContext')->once()->andReturn([
                'contact' => null,
                'company' => null,
            ]);
        });

        $this->actingAs($user)->post("/captures/{$capture->id}/reprocess")
            ->assertRedirect(route('captures.review', $capture));

        $capture->refresh();
        $this->assertSame('Alex Rivera', $capture->full_name);
        $this->assertTrue($district->is($capture->district));
        $this->assertSame('Curriculum leader', $capture->aiInsights()['role_category']);
    }

    public function test_public_email_search_requires_openai_api_key(): void
    {
        config(['services.openai.key' => null]);

        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'full_name' => 'Alex Rivera',
            'organization' => 'Cherry Creek School District',
        ]);

        $this->actingAs($user)->post("/captures/{$capture->id}/web-enrich")
            ->assertRedirect(route('captures.review', $capture))
            ->assertSessionHasErrors('web_enrichment');

        $this->assertSame('error', $capture->fresh()->publicEnrichment()['status']);
    }

    public function test_review_page_does_not_auto_start_public_email_search(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'full_name' => 'Alex Rivera',
            'organization' => 'Cherry Creek School District',
            'raw_text' => 'Alex Rivera Cherry Creek School District',
        ]);

        $this->actingAs($user)
            ->get(route('captures.review', $capture))
            ->assertOk()
            ->assertDontSee('data-testid="auto-public-email-enabled"', false);
    }

    public function test_review_form_appears_before_badge_clues(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'full_name' => 'Alex Rivera',
            'first_name' => 'Alex',
            'last_name' => 'Rivera',
            'organization' => 'Cherry Creek School District',
            'extracted_payload' => [
                'insights' => [
                    'role_category' => 'Curriculum leader',
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('captures.review', $capture))
            ->assertOk()
            ->assertSee('class="item-card insight-disclosure"', false)
            ->assertDontSee('class="item-card insight-disclosure" open', false)
            ->assertSeeInOrder(['First Name', 'Save Review', 'Back to Event', 'Delete Lead', 'Badge Clues', 'Add to HubSpot']);
    }

    public function test_review_image_appears_before_form_when_available(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'full_name' => 'Alex Rivera',
            'image_path' => 'captures/badge.jpg',
            'extracted_payload' => [
                'insights' => [
                    'role_category' => 'Curriculum leader',
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('captures.review', $capture))
            ->assertOk()
            ->assertSeeInOrder(['Captured badge or business card', 'First Name', 'Badge Clues']);
    }

    public function test_public_email_search_fills_blank_email_when_confident_and_sourced(): void
    {
        config(['services.openai.key' => 'test-key']);

        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        $district = District::create([
            'state_code' => 'CO',
            'lea_id' => '0802910',
            'name' => 'Cherry Creek SD',
        ]);
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'district_id' => $district->id,
            'status' => Capture::STATUS_REVIEWED,
            'full_name' => 'Alex Rivera',
            'organization' => 'Cherry Creek School District',
            'raw_text' => 'Alex Rivera Cherry Creek',
            'extracted_payload' => ['insights' => ['role_category' => 'Curriculum leader']],
        ]);

        $this->mock(PublicLeadEnricher::class, function ($mock): void {
            $mock->shouldReceive('enrich')->once()->andReturn([
                'status' => 'found',
                'email' => 'alex.rivera@cherrycreekschools.org',
                'confidence' => 0.84,
                'person_match' => 'Name matches a public staff page.',
                'organization_match' => 'Organization matches badge clues.',
                'summary' => 'Email found on official staff directory.',
                'sources' => [[
                    'title' => 'Staff Directory',
                    'url' => 'https://example.org/staff/alex-rivera',
                    'evidence' => 'Lists Alex Rivera email.',
                ]],
                'checked_at' => now()->toIso8601String(),
            ]);
        });

        $this->actingAs($user)->post("/captures/{$capture->id}/web-enrich")
            ->assertRedirect(route('captures.review', $capture));

        $capture->refresh();
        $this->assertSame('alex.rivera@cherrycreekschools.org', $capture->email);
        $this->assertSame(Capture::STATUS_COMPLETE, $capture->status);
        $this->assertSame('found', $capture->publicEnrichment()['status']);
        $this->assertSame('https://example.org/staff/alex-rivera', $capture->publicEnrichmentSources()[0]['url']);
    }

    public function test_public_email_search_uses_unsaved_manual_review_context(): void
    {
        config(['services.openai.key' => 'test-key']);

        $user = User::factory()->create();
        $event = Event::create(['name' => 'TX Math', 'state_code' => 'TX']);
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'full_name' => 'Amanda',
        ]);

        $this->mock(PublicLeadEnricher::class, function ($mock): void {
            $mock->shouldReceive('enrich')
                ->once()
                ->with(Mockery::on(fn (Capture $capture): bool => $capture->full_name === 'Amanda Dearing'
                    && $capture->organization === 'Grapevine-Colleyville ISD'
                    && $capture->title === 'High School Math Instructional Coach'
                    && str_contains((string) $capture->rep_notes, 'LinkedIn profile')
                    && str_contains((string) $capture->raw_text, 'TASM 2025')))
                ->andReturn([
                    'status' => 'found',
                    'email' => 'amanda.dearing@gcisd.net',
                    'confidence' => 0.9,
                    'person_match' => 'Manual notes and badge text match.',
                    'organization_match' => 'Organization matches manual context.',
                    'summary' => 'Email found on official staff directory.',
                    'sources' => [[
                        'title' => 'Staff Directory',
                        'url' => 'https://example.org/staff/amanda-dearing',
                        'evidence' => 'Lists Amanda Dearing email.',
                    ]],
                    'checked_at' => now()->toIso8601String(),
                ]);
        });

        $this->actingAs($user)->patch(route('captures.web-enrich', $capture), [
            'district_id' => '',
            'first_name' => 'Amanda',
            'last_name' => 'Dearing',
            'full_name' => 'Amanda Dearing',
            'email' => '',
            'phone' => '',
            'title' => 'High School Math Instructional Coach',
            'organization' => 'Grapevine-Colleyville ISD',
            'city' => 'Grapevine',
            'state' => 'TX',
            'raw_text' => 'Amanda Dearing Grapevine-Colleyville ISD TASM 2025',
            'rep_notes' => 'LinkedIn profile says high school math instructional coach.',
            'follow_up_status' => 'new',
        ])->assertRedirect(route('captures.review', $capture));

        $capture->refresh();
        $this->assertSame('Amanda Dearing', $capture->full_name);
        $this->assertSame('Grapevine-Colleyville ISD', $capture->organization);
        $this->assertSame('High School Math Instructional Coach', $capture->title);
        $this->assertSame('amanda.dearing@gcisd.net', $capture->email);
        $this->assertSame('found', $capture->publicEnrichment()['status']);
    }

    public function test_public_email_search_does_not_apply_masked_email(): void
    {
        config(['services.openai.key' => 'test-key']);

        $user = User::factory()->create();
        $event = Event::create(['name' => 'TX Math', 'state_code' => 'TX']);
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'full_name' => 'Amanda Dearing',
            'organization' => 'Grapevine-Colleyville ISD',
        ]);

        $this->mock(PublicLeadEnricher::class, function ($mock): void {
            $mock->shouldReceive('enrich')->once()->andReturn([
                'status' => 'found',
                'email' => 'a******d@gcisd-k12.org',
                'confidence' => 0.91,
                'person_match' => 'Name appears in source snippet.',
                'organization_match' => 'Organization matches badge clues.',
                'summary' => 'Source showed only a masked email.',
                'sources' => [[
                    'title' => 'Staff Directory',
                    'url' => 'https://example.org/staff/amanda-dearing',
                    'evidence' => 'Shows masked email a******d@gcisd-k12.org.',
                ]],
                'checked_at' => now()->toIso8601String(),
            ]);
        });

        $this->actingAs($user)->post("/captures/{$capture->id}/web-enrich")
            ->assertRedirect(route('captures.review', $capture));

        $capture->refresh();
        $this->assertNull($capture->email);
        $this->assertNull($capture->publicEnrichmentEmail());
        $this->assertSame('ambiguous', $capture->publicEnrichment()['status']);
        $this->assertStringContainsString('masked email', $capture->publicEnrichment()['summary']);
    }

    public function test_public_email_search_does_not_overwrite_existing_email(): void
    {
        config(['services.openai.key' => 'test-key']);

        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_REVIEWED,
            'full_name' => 'Alex Rivera',
            'email' => 'manual@example.org',
            'organization' => 'Cherry Creek School District',
        ]);

        $this->mock(PublicLeadEnricher::class, function ($mock): void {
            $mock->shouldReceive('enrich')->once()->andReturn([
                'status' => 'found',
                'email' => 'alex.rivera@cherrycreekschools.org',
                'confidence' => 0.9,
                'person_match' => 'Name matches.',
                'organization_match' => 'Organization matches.',
                'summary' => 'Email found on official staff directory.',
                'sources' => [[
                    'title' => 'Staff Directory',
                    'url' => 'https://example.org/staff/alex-rivera',
                    'evidence' => 'Lists Alex Rivera email.',
                ]],
                'checked_at' => now()->toIso8601String(),
            ]);
        });

        $this->actingAs($user)->post("/captures/{$capture->id}/web-enrich")
            ->assertRedirect(route('captures.review', $capture));

        $capture->refresh();
        $this->assertSame('manual@example.org', $capture->email);
        $this->assertSame('alex.rivera@cherrycreekschools.org', $capture->publicEnrichment()['email']);
    }

    public function test_public_email_search_reuses_existing_sourced_capture_for_same_person_and_organization(): void
    {
        config(['services.openai.key' => 'test-key']);

        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'full_name' => 'Alex Rivera',
            'email' => 'alex.rivera@cherrycreekschools.org',
            'organization' => 'Cherry Creek School District',
            'extracted_payload' => [
                'public_enrichment' => [
                    'status' => 'found',
                    'email' => 'alex.rivera@cherrycreekschools.org',
                    'confidence' => 0.91,
                    'summary' => 'Found on staff page.',
                    'sources' => [[
                        'title' => 'Staff Directory',
                        'url' => 'https://example.org/staff/alex-rivera',
                        'evidence' => 'Lists Alex Rivera email.',
                    ]],
                ],
            ],
        ]);
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'full_name' => 'Alex Rivera',
            'organization' => 'Cherry Creek School District',
        ]);

        $this->mock(PublicLeadEnricher::class, function ($mock): void {
            $mock->shouldNotReceive('enrich');
        });

        $this->actingAs($user)->post("/captures/{$capture->id}/web-enrich")
            ->assertRedirect(route('captures.review', $capture));

        $capture->refresh();
        $this->assertSame('alex.rivera@cherrycreekschools.org', $capture->email);
        $this->assertSame('found', $capture->publicEnrichment()['status']);
        $this->assertSame('https://example.org/staff/alex-rivera', $capture->publicEnrichmentSources()[0]['url']);
    }

    public function test_public_email_search_failure_is_recorded_to_prevent_auto_retry_loop(): void
    {
        config(['services.openai.key' => 'test-key']);

        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'full_name' => 'Alex Rivera',
            'organization' => 'Cherry Creek School District',
        ]);

        $this->mock(PublicLeadEnricher::class, function ($mock): void {
            $mock->shouldReceive('enrich')->once()->andThrow(new RuntimeException('Search timed out.'));
        });

        $this->actingAs($user)->post("/captures/{$capture->id}/web-enrich")
            ->assertRedirect(route('captures.review', $capture))
            ->assertSessionHasErrors('web_enrichment');

        $capture->refresh();
        $this->assertSame('error', $capture->publicEnrichment()['status']);
        $this->assertStringContainsString('Search timed out', $capture->publicEnrichment()['summary']);

        $this->actingAs($user)
            ->get(route('captures.review', $capture))
            ->assertDontSee('data-testid="auto-public-email-enabled"', false);
    }

    public function test_review_labels_public_email_not_found_as_no_complete_email(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'TX Math', 'state_code' => 'TX']);
        $capture = Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'full_name' => 'Amanda Dearing',
            'organization' => 'Grapevine-Colleyville ISD',
            'extracted_payload' => [
                'public_enrichment' => [
                    'status' => 'not_found',
                    'email' => null,
                    'confidence' => 0.96,
                    'summary' => 'No directly evidenced email address was visible.',
                    'sources' => [],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('captures.review', $capture))
            ->assertOk()
            ->assertSee('no complete email')
            ->assertSee('Search confidence')
            ->assertDontSee('not found');
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
