<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('DemoSeeder is blocked in production environment.');
            return;
        }

        $this->call(DemoSeeder::class);
    }
}
