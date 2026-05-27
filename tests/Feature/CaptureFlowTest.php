<?php

namespace Tests\Feature;

use App\Models\Capture;
use App\Models\District;
use App\Models\Event;
use App\Models\User;
use App\Services\HubSpotClient;
use App\Services\OpenAiLeadExtractor;
use App\Services\PublicLeadEnricher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CaptureFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_upload_runs_extraction_and_creates_review_record(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);
        $district = District::create([
            'state_code' => 'CO',
            'lea_id' => '0802910',
            'name' => 'Cherry Creek SD',
            'short_name' => 'Cherry Creek',
            'city' => 'Greenwood Village',
            'total_students' => 51980,
        ]);

        $this->mock(OpenAiLeadExtractor::class, function ($mock): void {
            $mock->shouldReceive('extract')->once()->andReturn([
                'full_name' => 'Alex Rivera',
                'first_name' => 'Alex',
                'last_name' => 'Rivera',
                'email' => 'alex@cherrycreekschools.org',
                'phone' => '555-0100',
                'title' => 'Math Director',
                'organization' => 'Cherry Creek School District',
                'city' => 'Greenwood Village',
                'state' => 'CO',
                'raw_text' => 'Alex Rivera Cherry Creek School District',
                'confidence' => ['overall' => 0.91],
                'evidence' => ['Cherry Creek School District'],
                'warnings' => [],
                'ai_confidence' => 0.91,
                'extracted_payload' => [],
            ]);
        });

        $response = $this->actingAs($user)->post('/captures', [
            'event_id' => $event->id,
            'photo' => UploadedFile::fake()->image('badge.jpg', 600, 400),
            'rep_notes' => 'Met at booth.',
        ]);

        $capture = Capture::firstOrFail();
        $response->assertRedirect(route('captures.review', $capture));

        $this->assertSame('Alex Rivera', $capture->full_name);
        $this->assertTrue($district->is($capture->district));
        Storage::disk('local')->assertExists($capture->image_path);
    }

    public function test_capture_photo_is_required(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);

        $this->actingAs($user)->post('/captures', [
            'event_id' => $event->id,
        ])->assertSessionHasErrors('photo');
    }

    public function test_capture_photo_must_be_readable_as_an_image(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'CO Math', 'state_code' => 'CO']);

        $this->actingAs($user)->post('/captures', [
            'event_id' => $event->id,
            'photo' => UploadedFile::fake()->create('badge.txt', 1, 'text/plain'),
        ])->assertSessionHasErrors('photo');
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

    public function test_review_page_auto_starts_public_email_search_when_email_is_missing(): void
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
            ->assertSee('data-testid="auto-public-email-enabled"', false);
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
            ->assertSeeInOrder(['First Name', 'Badge Clues']);
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
        $this->assertSame(Capture::STATUS_NEEDS_REVIEW, $capture->status);
        $this->assertSame('found', $capture->publicEnrichment()['status']);
        $this->assertSame('https://example.org/staff/alex-rivera', $capture->publicEnrichmentSources()[0]['url']);
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

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
