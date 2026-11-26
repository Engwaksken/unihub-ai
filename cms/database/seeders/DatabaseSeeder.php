<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Seeders contain synthetic data and must not run in production. Use php artisan cms:bootstrap-owner instead.');
        }

        $this->call(SyntheticStaffSeeder::class);
    }
}
