<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class InternalUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('INTERNAL_USER_PASSWORD', 'capture');

        foreach ($this->internalUsers() as $user) {
            User::updateOrCreate([
                'email' => $user['email'],
            ], [
                'name' => $user['name'],
                'password' => $password,
            ]);
        }
    }

    private function internalUsers(): array
    {
        return [
            ['name' => 'Eric Price', 'email' => 'eric.price@derivita.com'],
            ['name' => 'Duane', 'email' => 'duane@derivita.com'],
        ];
    }
}
