<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'azam@amiosh.com'],
            [
                'name' => 'Jang',
                'password' => Hash::make('dok ghok'),
                'email_verified_at' => now(),
                'status' => 'Active',
                'failed_login_count' => 0,
                'locked_at' => null,
                'locked_by' => null,
                'lock_reason' => null,
            ]
        );
    }
}
