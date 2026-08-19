<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with development data.
     *
     * This chain includes fabricated employees, attendance, leaves and
     * payslips, so it is barred from production. Use ProductionSeeder there.
     *
     * @throws RuntimeException when invoked in production
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'DatabaseSeeder seeds demo data and must not run in production. '
                .'Use `php artisan db:seed --class=ProductionSeeder --force` instead.'
            );
        }

        $this->call([
            AdminSeeder::class,
            EmployeeSeeder::class,
            DemoSeeder::class,
        ]);
    }
}
