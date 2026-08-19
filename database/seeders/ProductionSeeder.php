<?php

namespace Database\Seeders;

use App\Models\Shift;
use Illuminate\Database\Seeder;

class ProductionSeeder extends Seeder
{
    /**
     * Seed the minimum data a clean production database needs to be usable:
     * one administrator to log in with, and one active shift so employees have
     * office hours to be measured against. Everything else — departments,
     * positions, employees — is entered through the application.
     *
     * Run once after the first deploy:
     *
     *     php artisan db:seed --class=ProductionSeeder --force
     */
    public function run(): void
    {
        $this->call(AdminSeeder::class);

        Shift::firstOrCreate(
            ['name' => config('hris.default_shift.name')],
            [
                'check_in' => config('hris.default_shift.check_in'),
                'check_out' => config('hris.default_shift.check_out'),
                'late_threshold' => config('hris.default_shift.late_threshold'),
                'grace_minutes' => config('hris.default_shift.grace_minutes'),
                'break_enabled' => false,
                'is_active' => true,
            ]
        );
    }
}
