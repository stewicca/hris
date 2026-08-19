<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminSeeder extends Seeder
{
    /**
     * Create the bootstrap administrator.
     *
     * Credentials come from config/hris.php (ADMIN_EMAIL / ADMIN_PASSWORD).
     * In production there is no fallback: a missing value aborts the seed
     * rather than silently installing a guessable account.
     *
     * @throws RuntimeException when running in production without credentials
     */
    public function run(): void
    {
        $email = config('hris.admin.email');
        $password = config('hris.admin.password');

        if (app()->isProduction() && (blank($email) || blank($password))) {
            throw new RuntimeException(
                'ADMIN_EMAIL and ADMIN_PASSWORD must both be set in production. '
                .'Refusing to seed an administrator with default credentials.'
            );
        }

        User::firstOrCreate(
            ['username' => config('hris.admin.username')],
            [
                'name' => config('hris.admin.name'),
                'email' => $email ?: 'admin@hris.local',
                'password' => Hash::make($password ?: 'password'),
                'email_verified_at' => now(),
                'is_admin' => true,
            ]
        );
    }
}
