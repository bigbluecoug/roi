<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            DistrictSeeder::class,
            EventSeeder::class,
        ]);

        User::updateOrCreate([
            'email' => env('INTERNAL_USER_EMAIL', 'lead@example.com'),
        ], [
            'name' => env('INTERNAL_USER_NAME', 'Event Lead Rep'),
            'password' => Hash::make(env('INTERNAL_USER_PASSWORD', 'password')),
        ]);
    }
}
