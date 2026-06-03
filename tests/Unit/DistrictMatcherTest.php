<?php

namespace Tests\Unit;

use App\Models\District;
use App\Models\Event;
use App\Services\DistrictMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DistrictMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_matches_visible_organization_text_to_a_district(): void
    {
        $event = Event::create(['name' => 'Colorado Event', 'state_code' => 'CO']);
        $district = District::create([
            'state_code' => 'CO',
            'lea_id' => '0802910',
            'name' => 'Cherry Creek SD',
            'short_name' => 'Cherry Creek',
            'city' => 'Greenwood Village',
            'total_students' => 51980,
        ]);

        $match = (new DistrictMatcher)->match($event, [
            'organization' => 'Cherry Creek School District',
            'email' => 'alex@cherrycreekschools.org',
            'city' => 'Greenwood Village',
        ]);

        $this->assertTrue($district->is($match['district']));
        $this->assertGreaterThanOrEqual(0.68, $match['confidence']);
    }

    public function test_it_matches_raw_badge_and_ai_clues_when_organization_is_blank(): void
    {
        $event = Event::create(['name' => 'Oklahoma Event', 'state_code' => 'OK']);
        $district = District::create([
            'state_code' => 'OK',
            'lea_id' => '4015480',
            'name' => 'Putnam City',
            'short_name' => 'Putnam City',
            'city' => 'Oklahoma City',
            'total_students' => 17950,
        ]);

        $match = (new DistrictMatcher)->match($event, [
            'organization' => null,
            'email' => null,
            'city' => null,
            'raw_text' => 'Jordan Ellis instructional coach Putnam City Schools',
            'evidence' => ['Putnam City appears on the badge'],
            'insights' => [
                'district_clues' => ['Putnam City Schools'],
            ],
        ]);

        $this->assertTrue($district->is($match['district']));
        $this->assertGreaterThanOrEqual(0.68, $match['confidence']);
    }
}
