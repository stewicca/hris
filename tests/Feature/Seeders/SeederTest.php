<?php

use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\EmployeeSeeder;
use Database\Seeders\ProductionSeeder;
use Illuminate\Support\Facades\Hash;

/** Pretend the application booted with APP_ENV=production. */
function asProduction(): void
{
    app()->detectEnvironment(fn () => 'production');
}

describe('AdminSeeder', function () {
    it('creates the administrator from configured credentials', function () {
        config([
            'hris.admin.username' => 'ops',
            'hris.admin.name' => 'Ops Lead',
            'hris.admin.email' => 'ops@example.test',
            'hris.admin.password' => 'a-real-secret',
        ]);

        (new AdminSeeder)->run();

        $admin = User::where('username', 'ops')->sole();

        expect($admin->email)->toBe('ops@example.test')
            ->and($admin->is_admin)->toBeTrue()
            ->and(Hash::check('a-real-secret', $admin->password))->toBeTrue();
    });

    it('aborts in production when the password is missing', function () {
        asProduction();
        config(['hris.admin.email' => 'ops@example.test', 'hris.admin.password' => null]);

        expect(fn () => (new AdminSeeder)->run())
            ->toThrow(RuntimeException::class, 'ADMIN_EMAIL and ADMIN_PASSWORD');

        expect(User::count())->toBe(0);
    });

    it('aborts in production when the email is missing', function () {
        asProduction();
        config(['hris.admin.email' => null, 'hris.admin.password' => 'a-real-secret']);

        expect(fn () => (new AdminSeeder)->run())->toThrow(RuntimeException::class);
        expect(User::count())->toBe(0);
    });

    it('never falls back to a default password in production', function () {
        asProduction();
        config(['hris.admin.email' => null, 'hris.admin.password' => null]);

        expect(fn () => (new AdminSeeder)->run())->toThrow(RuntimeException::class);
        expect(User::where('username', 'admin')->exists())->toBeFalse();
    });
});

describe('ProductionSeeder', function () {
    beforeEach(function () {
        config([
            'hris.admin.username' => 'admin',
            'hris.admin.email' => 'admin@example.test',
            'hris.admin.password' => 'a-real-secret',
        ]);
    });

    it('seeds only an administrator and a default shift', function () {
        (new ProductionSeeder)->setContainer(app())->run();

        expect(User::count())->toBe(1)
            ->and(User::sole()->is_admin)->toBeTrue()
            ->and(Employee::count())->toBe(0)
            ->and(Shift::count())->toBe(1);

        $shift = Shift::sole();

        expect($shift->name)->toBe(config('hris.default_shift.name'))
            ->and($shift->is_active)->toBeTrue();
    });

    it('is idempotent', function () {
        (new ProductionSeeder)->setContainer(app())->run();
        (new ProductionSeeder)->setContainer(app())->run();

        expect(User::count())->toBe(1)
            ->and(Shift::count())->toBe(1);
    });

    it('runs in production', function () {
        asProduction();

        (new ProductionSeeder)->setContainer(app())->run();

        expect(User::count())->toBe(1)
            ->and(Shift::count())->toBe(1);
    });
});

describe('demo seeders', function () {
    it('refuses to run DatabaseSeeder in production', function () {
        asProduction();

        expect(fn () => (new DatabaseSeeder)->setContainer(app())->run())
            ->toThrow(RuntimeException::class, 'must not run in production');

        expect(User::count())->toBe(0);
    });

    it('makes EmployeeSeeder a no-op in production', function () {
        asProduction();

        (new EmployeeSeeder)->run();

        expect(User::count())->toBe(0)
            ->and(Employee::count())->toBe(0);
    });

    it('makes DemoSeeder a no-op in production', function () {
        asProduction();

        (new DemoSeeder)->run();

        expect(Employee::count())->toBe(0);
    });

    it('still seeds demo data outside production', function () {
        (new DatabaseSeeder)->setContainer(app())->run();

        expect(User::where('username', 'budi')->exists())->toBeTrue()
            ->and(Employee::count())->toBeGreaterThan(1);
    });
});
