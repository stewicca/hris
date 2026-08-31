<?php

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);
});

// Index
test('employee index page is accessible', function () {
    Employee::factory()->count(3)->create();

    $this->get(route('employees.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('employees/index')
            ->has('employees.data', 3)
        );
});

test('employee index redirects guests', function () {
    auth()->logout();

    $this->get(route('employees.index'))->assertRedirect(route('login'));
});

// Create
test('employee create page is accessible', function () {
    $this->get(route('employees.create'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('employees/create'));
});

// Store
test('employee can be created with a generated user account', function () {
    $department = Department::factory()->create();
    $position = Position::factory()->create();

    $response = $this->post(route('employees.store'), [
        'name' => 'Budi Santoso',
        'email' => 'budi@example.com',
        'phone' => '081234567890',
        'department_id' => $department->id,
        'position_id' => $position->id,
        'hire_date' => '2024-01-15',
        'status' => 'active',
    ]);

    $response->assertRedirect(route('employees.index'));

    $employee = Employee::where('email', 'budi@example.com')->first();
    expect($employee)->not->toBeNull();
    expect($employee->name)->toBe('Budi Santoso');
    expect($employee->employee_number)->toStartWith('EMP');
    expect($employee->user_id)->not->toBeNull();
    expect($employee->department_id)->toBe($department->id);
    expect($employee->position_id)->toBe($position->id);

    $user = User::where('email', 'budi@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Budi Santoso');
    expect($user->is_admin)->toBeFalse();
});

test('employee can be created as an admin user', function () {
    $this->post(route('employees.store'), [
        'name' => 'Admin Baru',
        'email' => 'adminbaru@example.com',
        'status' => 'active',
        'is_admin' => '1',
    ])->assertRedirect(route('employees.index'));

    $user = User::where('email', 'adminbaru@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->is_admin)->toBeTrue();
});

test('generated password is flashed to session after employee creation', function () {
    $this->post(route('employees.store'), [
        'name' => 'Siti Rahayu',
        'email' => 'siti@example.com',
        'status' => 'active',
    ])->assertSessionHas('generated_password');
});

test('employee store validates required fields', function () {
    $this->post(route('employees.store'), [])
        ->assertSessionHasErrors(['name', 'email', 'status']);
});

test('employee store rejects duplicate email', function () {
    Employee::factory()->create(['email' => 'existing@example.com']);

    $this->post(route('employees.store'), [
        'name' => 'New Person',
        'email' => 'existing@example.com',
        'status' => 'active',
    ])->assertSessionHasErrors(['email']);
});

test('employee number is auto-generated sequentially', function () {
    $this->post(route('employees.store'), [
        'name' => 'First Employee',
        'email' => 'first@example.com',
        'status' => 'active',
    ]);

    $this->post(route('employees.store'), [
        'name' => 'Second Employee',
        'email' => 'second@example.com',
        'status' => 'active',
    ]);

    $employees = Employee::orderBy('id')->get();
    expect($employees[0]->employee_number)->toBe('EMP0001');
    expect($employees[1]->employee_number)->toBe('EMP0002');
});

test('employee number generation handles an empty table and multi-digit rollover', function () {
    expect(Employee::generateEmployeeNumber())->toBe('EMP0001');

    Employee::factory()->create(['employee_number' => 'EMP0009']);
    expect(Employee::generateEmployeeNumber())->toBe('EMP0010');

    Employee::factory()->create(['employee_number' => 'EMP0099']);
    expect(Employee::generateEmployeeNumber())->toBe('EMP0100');
});

test('the employee number lookup takes a row lock on mysql', function () {
    // The suite runs on SQLite, whose grammar drops FOR UPDATE silently — so
    // the lock that makes concurrent creates safe in production is invisible
    // to every other test here. Compile the same query against the MySQL
    // grammar (no connection is opened) to keep it from being removed.
    $sql = Employee::on('mysql')->latest('id')->lockForUpdate()->toSql();

    expect($sql)->toContain('for update');
});

test('a failure creating the employee leaves no orphaned user account behind', function () {
    // Fail the second insert once the user row already exists — the exact
    // window that used to strand a login with no employee attached to it.
    Employee::creating(fn () => throw new RuntimeException('insert failed'));

    $this->withoutExceptionHandling();

    expect(fn () => $this->post(route('employees.store'), [
        'name' => 'Gagal Tengah Jalan',
        'email' => 'gagal@example.com',
        'status' => 'active',
    ]))->toThrow(RuntimeException::class);

    expect(User::where('email', 'gagal@example.com')->exists())->toBeFalse()
        ->and(Employee::where('email', 'gagal@example.com')->exists())->toBeFalse();
});

test('employee creation retries when a generated value loses a race', function () {
    $attempts = 0;

    // Stands in for a concurrent create that committed the same generated
    // username a moment earlier — something the losing attempt cannot see
    // from inside its own transaction.
    User::creating(function () use (&$attempts): void {
        $attempts++;

        if ($attempts === 1) {
            throw new UniqueConstraintViolationException(
                'sqlite',
                'insert into "users" ("username") values (?)',
                [],
                new Exception('UNIQUE constraint failed: users.username'),
            );
        }
    });

    $this->post(route('employees.store'), [
        'name' => 'Budi Kembar',
        'email' => 'kembar@example.com',
        'status' => 'active',
    ])->assertRedirect(route('employees.index'));

    expect($attempts)->toBe(2)
        ->and(Employee::where('email', 'kembar@example.com')->exists())->toBeTrue();
});

test('employee creation gives up after repeated collisions and leaves nothing behind', function () {
    $attempts = 0;

    User::creating(function () use (&$attempts): void {
        $attempts++;

        throw new UniqueConstraintViolationException(
            'sqlite',
            'insert into "users" ("username") values (?)',
            [],
            new Exception('UNIQUE constraint failed: users.username'),
        );
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->post(route('employees.store'), [
        'name' => 'Selalu Bentrok',
        'email' => 'bentrok@example.com',
        'status' => 'active',
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect($attempts)->toBe(3)
        ->and(User::where('email', 'bentrok@example.com')->exists())->toBeFalse()
        ->and(Employee::where('email', 'bentrok@example.com')->exists())->toBeFalse();
});

test('employee can be created with a bank account number', function () {
    $this->post(route('employees.store'), [
        'name' => 'Budi Rekening',
        'email' => 'rekening@example.com',
        'bank_account_number' => '1234567890',
        'status' => 'active',
    ])->assertRedirect(route('employees.index'));

    $employee = Employee::where('email', 'rekening@example.com')->first();
    expect($employee)->not->toBeNull();
    expect($employee->bank_account_number)->toBe('1234567890');
});

test('employee store accepts a nullable bank account number', function () {
    $this->post(route('employees.store'), [
        'name' => 'No Rekening',
        'email' => 'norek@example.com',
        'status' => 'active',
    ])->assertRedirect(route('employees.index'));

    $employee = Employee::where('email', 'norek@example.com')->first();
    expect($employee->bank_account_number)->toBeNull();
});

test('employee store rejects a bank account number longer than 50 chars', function () {
    $this->post(route('employees.store'), [
        'name' => 'Panjang Rekening',
        'email' => 'panjang@example.com',
        'bank_account_number' => str_repeat('1', 51),
        'status' => 'active',
    ])->assertSessionHasErrors(['bank_account_number']);
});

// Show
test('employee show page renders correct component with employee data', function () {
    $employee = Employee::factory()->create([
        'bank_account_number' => '9876543210',
    ]);

    $this->get(route('employees.show', $employee))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('employees/show')
            ->has('employee')
            ->where('employee.id', $employee->id)
            ->where('employee.bank_account_number', '9876543210')
            ->has('attendanceSummary')
            ->where('attendanceSummary.present', 0)
            ->where('attendanceSummary.late', 0)
            ->where('attendanceSummary.absent', 0)
            ->where('attendanceSummary.total', 0)
            ->has('monthlyRecap')
        );
});

test('employee show page returns monthly recap for last 12 months', function () {
    $employee = Employee::factory()->create();

    Attendance::factory()->create(['employee_id' => $employee->id, 'status' => 'present', 'date' => now()->startOfMonth()]);
    Attendance::factory()->create(['employee_id' => $employee->id, 'status' => 'late', 'date' => now()->subMonthsNoOverflow(1)->startOfMonth()]);
    // Older than 12 months — should not appear
    Attendance::factory()->create(['employee_id' => $employee->id, 'status' => 'present', 'date' => now()->subMonthsNoOverflow(13)->startOfMonth()]);

    $this->get(route('employees.show', $employee))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('monthlyRecap', 2)
            ->where('monthlyRecap.0.present', 1)
            ->where('monthlyRecap.1.late', 1)
        );
});

test('employee show page keeps the oldest recap month when viewed on a month end', function () {
    // Viewed on the 31st, the 11-months-back cutoff used to overflow into the
    // month after the one it should reach, silently dropping the oldest month
    // from the recap. The existing recap test builds its fixtures with
    // subMonthsNoOverflow, so it never sees this.
    $this->travelTo('2026-08-31 09:00:00');

    $employee = Employee::factory()->create();

    Attendance::factory()->create([
        'employee_id' => $employee->id,
        'status' => 'present',
        'date' => '2025-09-15',
    ]);

    $this->get(route('employees.show', $employee))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->has('monthlyRecap', 1));
});

test('employee attendance export returns csv download', function () {
    $employee = Employee::factory()->create();

    Attendance::factory()->create([
        'employee_id' => $employee->id,
        'date' => today(),
        'status' => 'present',
        'check_in' => '08:00:00',
        'check_out' => '17:00:00',
    ]);

    $response = $this->get(route('employees.attendance.export', $employee));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'attachment; filename=kehadiran-'.Str::slug($employee->name).'-'.now()->format('Ymd').'.csv');
});

test('employee show page summarises current month attendance correctly', function () {
    $employee = Employee::factory()->create();

    Attendance::factory()->create(['employee_id' => $employee->id, 'status' => 'present', 'date' => now()->startOfMonth()]);
    Attendance::factory()->create(['employee_id' => $employee->id, 'status' => 'present', 'date' => now()->startOfMonth()->addDays(1)]);
    Attendance::factory()->create(['employee_id' => $employee->id, 'status' => 'late', 'date' => now()->startOfMonth()->addDays(2)]);
    Attendance::factory()->create(['employee_id' => $employee->id, 'status' => 'absent', 'date' => now()->startOfMonth()->addDays(3)]);
    // Previous month — should not appear in summary
    Attendance::factory()->create(['employee_id' => $employee->id, 'status' => 'present', 'date' => now()->subMonthNoOverflow()->startOfMonth()]);

    $this->get(route('employees.show', $employee))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('attendanceSummary.present', 2)
            ->where('attendanceSummary.late', 1)
            ->where('attendanceSummary.absent', 1)
            ->where('attendanceSummary.total', 4)
        );
});

test('employee show page redirects guests', function () {
    auth()->logout();
    $employee = Employee::factory()->create();

    $this->get(route('employees.show', $employee))->assertRedirect(route('login'));
});

// Edit
test('employee edit page is accessible', function () {
    $employee = Employee::factory()->create();

    $this->get(route('employees.edit', $employee))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('employees/edit')
            ->has('employee')
            ->where('employee.id', $employee->id)
        );
});

// Update
test('employee can be updated', function () {
    $employee = Employee::factory()->create(['name' => 'Old Name', 'status' => 'active']);

    $this->put(route('employees.update', $employee), [
        'name' => 'New Name',
        'email' => $employee->email,
        'status' => 'inactive',
    ])->assertRedirect(route('employees.index'));

    expect($employee->fresh()->name)->toBe('New Name');
    expect($employee->fresh()->status)->toBe('inactive');
});

test('employee can update the bank account number', function () {
    $employee = Employee::factory()->create(['bank_account_number' => '1111111111']);

    $this->put(route('employees.update', $employee), [
        'name' => $employee->name,
        'email' => $employee->email,
        'status' => $employee->status,
        'bank_account_number' => '2222222222',
    ])->assertRedirect(route('employees.index'));

    expect($employee->fresh()->bank_account_number)->toBe('2222222222');
});

test('employee update can clear the bank account number', function () {
    $employee = Employee::factory()->create(['bank_account_number' => '1111111111']);

    $this->put(route('employees.update', $employee), [
        'name' => $employee->name,
        'email' => $employee->email,
        'status' => $employee->status,
        'bank_account_number' => '',
    ])->assertRedirect(route('employees.index'));

    expect($employee->fresh()->bank_account_number)->toBeNull();
});

test('employee update also updates linked user name and email', function () {
    $linkedUser = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);
    $employee = Employee::factory()->create([
        'user_id' => $linkedUser->id,
        'name' => 'Old Name',
        'email' => 'old@example.com',
    ]);

    $this->put(route('employees.update', $employee), [
        'name' => 'New Name',
        'email' => 'new@example.com',
        'status' => 'active',
    ])->assertRedirect(route('employees.index'));

    expect($linkedUser->fresh()->name)->toBe('New Name');
    expect($linkedUser->fresh()->email)->toBe('new@example.com');
});

test('employee update promotes linked user to admin', function () {
    $linkedUser = User::factory()->create(['is_admin' => false]);
    $employee = Employee::factory()->create([
        'user_id' => $linkedUser->id,
        'name' => $linkedUser->name,
        'email' => $linkedUser->email,
    ]);

    $this->put(route('employees.update', $employee), [
        'name' => $employee->name,
        'email' => $employee->email,
        'status' => 'active',
        'is_admin' => '1',
    ])->assertRedirect(route('employees.index'));

    expect($linkedUser->fresh()->is_admin)->toBeTrue();
});

test('employee update validates required fields', function () {
    $employee = Employee::factory()->create();

    $this->put(route('employees.update', $employee), [])
        ->assertSessionHasErrors(['name', 'email', 'status']);
});

// Destroy
test('employee can be deleted', function () {
    $employee = Employee::factory()->create();

    $this->delete(route('employees.destroy', $employee))
        ->assertRedirect(route('employees.index'));

    expect(Employee::find($employee->id))->toBeNull();
});

test('employee deletion does not delete linked user', function () {
    $linkedUser = User::factory()->create();
    $employee = Employee::factory()->create(['user_id' => $linkedUser->id]);

    $this->delete(route('employees.destroy', $employee));

    expect(User::find($linkedUser->id))->not->toBeNull();
});
