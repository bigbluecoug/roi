<?php

namespace Database\Seeders;

use App\Http\Controllers\EventController;
use App\Models\Event;
use Illuminate\Database\Seeder;

class EventSeeder extends Seeder
{
    public function run(): void
    {
        foreach (EventController::STATES as $code => $name) {
            Event::firstOrCreate(
                ['name' => "{$name} Field Capture", 'state_code' => $code],
                ['active' => true],
            );
        }
    }
}
