<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class InternalUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('internal-users.password', 'capture');

        foreach (config('internal-users.users', []) as $user) {
            User::updateOrCreate([
                'email' => $user['email'],
            ], [
                'name' => $user['name'],
                'password' => $password,
            ]);
        }
    }
}
