<?php

namespace Database\Seeders;

use App\Models\Event;
use Illuminate\Database\Seeder;

class EventSeeder extends Seeder
{
    private const STATES = [
        'CO' => 'Colorado',
        'UT' => 'Utah',
        'TX' => 'Texas',
        'CA' => 'California',
        'IL' => 'Illinois',
        'GA' => 'Georgia',
        'FL' => 'Florida',
        'MO' => 'Missouri',
        'OK' => 'Oklahoma',
    ];

    public function run(): void
    {
        foreach (self::STATES as $code => $name) {
            Event::firstOrCreate(
                ['name' => "{$name} Field Capture", 'state_code' => $code],
                ['active' => true],
            );
        }
    }
}
