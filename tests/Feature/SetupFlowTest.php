<?php

namespace Tests\Feature;

use App\Models\Capture;
use App\Models\District;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\DistrictSeeder;
use Database\Seeders\EventSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_redirects_to_state_setup_when_no_event_is_selected(): void
    {
        $user = User::factory()->create([
            'email' => 'rep@example.com',
            'password' => 'password',
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('setup.state'));
    }

    public function test_state_selection_stores_state_and_moves_to_events(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/setup/state', [
            'state_code' => 'CO',
        ])
            ->assertRedirect(route('setup.events'))
            ->assertSessionHas('current_state_code', 'CO')
            ->assertSessionMissing('current_event_id');
    }

    public function test_state_picker_includes_oklahoma(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/setup/state')
            ->assertOk()
            ->assertSee('OK')
            ->assertSee('Oklahoma');
    }

    public function test_oklahoma_seeders_create_event_and_district_profiles(): void
    {
        $this->seed([
            DistrictSeeder::class,
            EventSeeder::class,
        ]);

        $this->assertDatabaseHas('events', [
            'name' => 'Oklahoma Field Capture',
            'state_code' => 'OK',
        ]);
        $this->assertDatabaseHas('districts', [
            'state_code' => 'OK',
            'name' => 'Tulsa Public Schools',
        ]);
    }

    public function test_event_setup_filters_to_selected_state(): void
    {
        $user = User::factory()->create();
        Event::create(['name' => 'Colorado Math', 'state_code' => 'CO']);
        Event::create(['name' => 'Texas Math', 'state_code' => 'TX']);

        $this->actingAs($user)
            ->withSession(['current_state_code' => 'CO'])
            ->get('/setup/events')
            ->assertOk()
            ->assertSee('Colorado Math')
            ->assertDontSee('Texas Math');
    }

    public function test_event_workspace_shows_only_captures_for_that_event(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'URSA', 'state_code' => 'UT']);
        $otherEvent = Event::create(['name' => 'Utah Field Capture', 'state_code' => 'UT']);
        $district = District::create([
            'state_code' => 'UT',
            'lea_id' => '4900960',
            'name' => 'Washington District',
        ]);

        Capture::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'district_id' => $district->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'full_name' => 'Jenny Walker',
            'email' => null,
            'organization' => 'Washington County District',
        ]);
        Capture::create([
            'user_id' => $user->id,
            'event_id' => $otherEvent->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'full_name' => 'Other Lead',
            'organization' => 'Another District',
        ]);

        $this->actingAs($user)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSessionHas('current_state_code', 'UT')
            ->assertSessionHas('current_event_id', $event->id)
            ->assertSee('Jenny Walker')
            ->assertSee('Washington District')
            ->assertDontSee('Other Lead');
    }

    public function test_event_selection_stores_event_and_redirects_to_capture(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'Colorado Math', 'state_code' => 'CO']);

        $this->actingAs($user)->post('/setup/events/select', [
            'event_id' => $event->id,
        ])
            ->assertRedirect(route('captures.create'))
            ->assertSessionHas('current_state_code', 'CO')
            ->assertSessionHas('current_event_id', $event->id);
    }

    public function test_quick_event_creation_selects_the_new_event(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/events', [
            'name' => 'Colorado Conference',
            'state_code' => 'CO',
            'starts_on' => '2026-06-01',
            'venue' => 'Denver',
        ]);

        $event = Event::firstOrFail();

        $response
            ->assertRedirect(route('captures.create'))
            ->assertSessionHas('current_state_code', 'CO')
            ->assertSessionHas('current_event_id', $event->id);

        $this->assertSame('Colorado Conference', $event->name);
    }

    public function test_capture_requires_selected_event(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/capture')
            ->assertRedirect(route('setup.state'));

        $this->actingAs($user)
            ->withSession(['current_state_code' => 'CO'])
            ->get('/capture')
            ->assertRedirect(route('setup.events'));
    }

    public function test_capture_deep_link_selects_event(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'Colorado Math', 'state_code' => 'CO']);

        $this->actingAs($user)
            ->get('/capture?event='.$event->id)
            ->assertOk()
            ->assertSessionHas('current_state_code', 'CO')
            ->assertSessionHas('current_event_id', $event->id)
            ->assertSee('Take Photo and Extract Lead');
    }
}
