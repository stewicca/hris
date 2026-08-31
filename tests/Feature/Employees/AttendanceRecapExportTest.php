<?php

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\Setting;
use App\Models\User;
use App\Support\PayrollDeductionSettings;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();

    // A Monday to a Friday: five working days, no weekend in the way.
    $this->start = '2026-06-01';
    $this->end = '2026-06-05';
});

/** Fetch the recap sheet as a list of rows, header first. */
function recapRows(array $query = []): array
{
    $csv = test()->actingAs(test()->admin)
        ->get(route('employees.attendance.recap', [
            'start' => test()->start,
            'end' => test()->end,
            ...$query,
        ]))
        ->assertOk()
        ->streamedContent();

    $lines = array_filter(explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF"))));

    return array_map(fn (string $line) => str_getcsv(trim($line, "\r")), $lines);
}

/** Store one rule group on top of the all-off defaults. */
function storeRecapRules(array $override): void
{
    PayrollDeductionSettings::save(array_replace_recursive(PayrollDeductionSettings::DEFAULTS, $override));
}

it('gives every active employee one row under a named header', function () {
    Employee::factory()->active()->create(['name' => 'Andi']);
    Employee::factory()->active()->create(['name' => 'Budi']);

    $rows = recapRows();

    expect($rows[0])->toBe([
        'Nama', 'No. Karyawan', 'Departemen', 'Jabatan', 'Hari Kerja',
        'Hadir', 'Terlambat', 'Absen', 'Izin/Sakit', 'Cuti',
        'Potongan Terlambat', 'Potongan Pulang Cepat', 'Potongan Istirahat',
        'Potongan Absen', 'Total Potongan',
    ])
        ->and($rows)->toHaveCount(3)
        ->and($rows[1][0])->toBe('Andi')
        ->and($rows[2][0])->toBe('Budi');
});

it('counts each status and the working days they are read against', function () {
    $employee = Employee::factory()->active()->create(['name' => 'Andi']);

    Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-06-01', 'status' => 'present', 'check_in' => '08:00:00', 'check_out' => '17:00:00']);
    Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-06-02', 'status' => 'late', 'check_in' => '08:20:00', 'check_out' => '17:00:00']);
    Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-06-03', 'status' => 'absent', 'check_in' => null, 'check_out' => null]);
    Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-06-04', 'status' => 'sick', 'check_in' => null, 'check_out' => null]);

    [, $row] = recapRows();

    // Hari Kerja, Hadir, Terlambat, Absen, Izin/Sakit, Cuti
    expect(array_slice($row, 4, 6))->toBe(['5', '1', '1', '1', '1', '0']);
});

it('counts approved leave, which leaves no attendance row behind it', function () {
    $employee = Employee::factory()->active()->create();

    Leave::factory()->create([
        'employee_id' => $employee->id,
        'status' => 'approved',
        'start_date' => '2026-06-02',
        'end_date' => '2026-06-03',
    ]);

    [, $row] = recapRows();

    expect($row[9])->toBe('2');
});

it('does not let two overlapping leaves bill the same day twice', function () {
    $employee = Employee::factory()->active()->create();

    Leave::factory()->count(2)->sequence(
        ['start_date' => '2026-06-02', 'end_date' => '2026-06-03'],
        ['start_date' => '2026-06-03', 'end_date' => '2026-06-04'],
    )->create(['employee_id' => $employee->id, 'status' => 'approved']);

    [, $row] = recapRows();

    expect($row[9])->toBe('3');
});

it('clips a leave that runs past either end of the range', function () {
    $employee = Employee::factory()->active()->create();

    Leave::factory()->create([
        'employee_id' => $employee->id,
        'status' => 'approved',
        'start_date' => '2026-05-25',
        'end_date' => '2026-06-30',
    ]);

    [, $row] = recapRows();

    // Only the five working days inside the range count.
    expect($row[9])->toBe('5');
});

it('skips the weekend in both the working days and the leave it counts', function () {
    // Monday the 1st to the Monday after: six working days with a weekend
    // sitting in the middle of them.
    $this->end = '2026-06-08';

    $employee = Employee::factory()->active()->create();

    // Friday to Monday: four calendar days, two of them worked.
    Leave::factory()->create([
        'employee_id' => $employee->id,
        'status' => 'approved',
        'start_date' => '2026-06-05',
        'end_date' => '2026-06-08',
    ]);

    [, $row] = recapRows();

    expect($row[4])->toBe('6')
        ->and($row[9])->toBe('2');
});

it('breaks the deduction out by rule and totals it', function () {
    Setting::set('attendance_break_enabled', true);
    storeRecapRules([
        'late' => ['enabled' => true, 'basis' => 'check_in', 'tiers' => [['from_minutes' => 15, 'amount' => 15_000]]],
        'early_leave' => ['enabled' => true, 'tiers' => [['from_minutes' => 15, 'amount' => 20_000]]],
        'break_overrun' => ['enabled' => true, 'tiers' => [['from_minutes' => 15, 'amount' => 5_000]]],
        'absent' => ['enabled' => true, 'amount' => 100_000],
    ]);

    $employee = Employee::factory()->active()->create();

    Attendance::factory()->create([
        'employee_id' => $employee->id,
        'date' => '2026-06-01',
        'status' => 'late',
        'check_in' => '08:20:00',
        'check_out' => '16:30:00',
        'break_start' => '12:00:00',
        'break_end' => '13:25:00',
    ]);
    Attendance::factory()->create([
        'employee_id' => $employee->id,
        'date' => '2026-06-02',
        'status' => 'absent',
        'check_in' => null,
        'check_out' => null,
    ]);

    [, $row] = recapRows();

    // Terlambat, Pulang Cepat, Istirahat, Absen, Total
    expect(array_slice($row, 10, 5))->toBe(['15000', '20000', '5000', '100000', '140000']);
});

it('leaves the money columns at zero when no rule is configured', function () {
    $employee = Employee::factory()->active()->create();

    Attendance::factory()->create([
        'employee_id' => $employee->id,
        'date' => '2026-06-01',
        'status' => 'late',
        'check_in' => '09:30:00',
        'check_out' => '15:00:00',
    ]);

    [, $row] = recapRows();

    expect(array_slice($row, 10, 5))->toBe(['0', '0', '0', '0', '0']);
});

it('ignores attendance outside the range', function () {
    storeRecapRules(['absent' => ['enabled' => true, 'amount' => 100_000]]);

    $employee = Employee::factory()->active()->create();

    foreach (['2026-05-29', '2026-06-03', '2026-06-30'] as $date) {
        Attendance::factory()->create([
            'employee_id' => $employee->id,
            'date' => $date,
            'status' => 'absent',
            'check_in' => null,
            'check_out' => null,
        ]);
    }

    [, $row] = recapRows();

    expect($row[7])->toBe('1')
        ->and($row[14])->toBe('100000');
});

it('narrows the sheet to one department', function () {
    $production = Department::factory()->create(['name' => 'Produksi']);
    $finance = Department::factory()->create(['name' => 'Keuangan']);

    Employee::factory()->active()->create(['name' => 'Andi', 'department_id' => $production->id]);
    Employee::factory()->active()->create(['name' => 'Budi', 'department_id' => $finance->id]);

    $rows = recapRows(['department' => 'Produksi']);

    expect($rows)->toHaveCount(2)
        ->and($rows[1][0])->toBe('Andi')
        ->and($rows[1][2])->toBe('Produksi');
});

it('keeps someone who left mid-range but drops someone long gone', function () {
    $resigned = Employee::factory()->create(['name' => 'Andi', 'status' => 'inactive']);
    Employee::factory()->create(['name' => 'Citra', 'status' => 'inactive']);

    Attendance::factory()->create([
        'employee_id' => $resigned->id,
        'date' => '2026-06-02',
        'status' => 'present',
        'check_in' => '08:00:00',
        'check_out' => '17:00:00',
    ]);

    $rows = recapRows();

    expect($rows)->toHaveCount(2)
        ->and($rows[1][0])->toBe('Andi');
});

it('names the file after the range it covers', function () {
    Employee::factory()->active()->create();

    $this->actingAs($this->admin)
        ->get(route('employees.attendance.recap', ['start' => $this->start, 'end' => $this->end]))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'attachment; filename=rekap-kehadiran-20260601-20260605.csv');
});

it('rejects a range that does not make sense', function (array $query, string $field) {
    $this->actingAs($this->admin)
        ->get(route('employees.attendance.recap', $query))
        ->assertSessionHasErrors($field);
})->with([
    'no start' => [['end' => '2026-06-05'], 'start'],
    'no end' => [['start' => '2026-06-01'], 'end'],
    'end before start' => [['start' => '2026-06-05', 'end' => '2026-06-01'], 'end'],
    'end in the future' => [['start' => '2026-06-01', 'end' => '2099-01-01'], 'end'],
    'unknown department' => [['start' => '2026-06-01', 'end' => '2026-06-05', 'department' => 'Hantu'], 'department'],
]);

it('refuses a range longer than a year', function () {
    $this->actingAs($this->admin)
        ->get(route('employees.attendance.recap', ['start' => '2025-01-01', 'end' => '2026-06-05']))
        ->assertSessionHasErrors('end');
});

it('accepts a range of exactly the maximum length', function () {
    Employee::factory()->active()->create();

    $this->actingAs($this->admin)
        ->get(route('employees.attendance.recap', ['start' => '2025-06-06', 'end' => '2026-06-05']))
        ->assertOk();
});

it('keeps the recap behind the admin gate', function () {
    $this->get(route('employees.attendance.recap', ['start' => $this->start, 'end' => $this->end]))
        ->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get(route('employees.attendance.recap', ['start' => $this->start, 'end' => $this->end]))
        ->assertForbidden();
});

it('offers the department list to the employees page', function () {
    Department::factory()->create(['name' => 'Produksi']);

    $this->actingAs($this->admin)
        ->get(route('employees.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('departments', ['Produksi']));
});
