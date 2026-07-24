<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ProductSeeder::class);

        User::factory()->create([
            'name' => 'Platform Admin',
            'email' => 'admin@gmail.com',
            'password' => bcrypt('password'),
            'is_platform_admin' => true,
            'entitlements' => config('billing.bundles.full'),
        ]);

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'entitlements' => ['FE'],
        ]);
    }
}
