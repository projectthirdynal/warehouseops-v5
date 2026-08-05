<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DefaultUserSeeder extends Seeder
{
    /**
     * Seed the default admin user if no users exist.
     */
    public function run(): void
    {
        if (User::exists()) {
            return;
        }

        User::create([
            'name' => 'Administrator',
            'email' => 'admin@warehouseops.local',
            'phone' => null,
            'role' => 'superadmin',
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);
    }
}
