<?php

use App\Http\Controllers\Attendance\AttendanceController;
use App\Http\Controllers\AttendanceSettingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Employees\EmployeeController;
use App\Http\Controllers\FeatureSettingController;
use App\Http\Controllers\Leave\LeaveController;
use App\Http\Controllers\MasterData\MasterDataController;
use App\Http\Controllers\PayrollDeductionSettingController;
use App\Http\Controllers\Salary\SalaryController;
use App\Http\Controllers\Shifts\ShiftController;
use App\Http\Controllers\WorkCalendarController;
use App\Http\Middleware\EnsureLeaveFeatureEnabled;
use App\Http\Middleware\EnsurePayrollFeatureEnabled;
use App\Http\Middleware\EnsureShiftFeatureEnabled;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', EnsureUserIsAdmin::class])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::post('attendance', [AttendanceController::class, 'store'])->name('attendance.store');
    Route::delete('attendance/{attendance}', [AttendanceController::class, 'destroy'])->name('attendance.destroy');

    Route::middleware(EnsureLeaveFeatureEnabled::class)->group(function () {
        Route::resource('leaves', LeaveController::class)->except(['edit', 'update'])->parameters(['leaves' => 'leave']);
        Route::post('leaves/{leave}/approve', [LeaveController::class, 'approve'])->name('leaves.approve');
        Route::post('leaves/{leave}/reject', [LeaveController::class, 'reject'])->name('leaves.reject');
    });

    Route::resource('employees', EmployeeController::class);
    Route::post('employees/{employee}/reset-password', [EmployeeController::class, 'resetPassword'])->name('employees.reset-password');
    Route::get('employees/{employee}/attendance/export', [EmployeeController::class, 'attendanceExport'])->name('employees.attendance.export');

    Route::middleware(EnsurePayrollFeatureEnabled::class)->group(function () {
        Route::post('employees/{employee}/salaries', [SalaryController::class, 'store'])->name('employees.salaries.store');
        Route::get('salaries/{salary}/print', [SalaryController::class, 'print'])->name('salaries.print');
        Route::post('salaries/{salary}/mark-paid', [SalaryController::class, 'markPaid'])->name('salaries.mark-paid');
        Route::delete('salaries/{salary}', [SalaryController::class, 'destroy'])->name('salaries.destroy');

        Route::get('payroll-deduction-settings', [PayrollDeductionSettingController::class, 'index'])->name('payroll-deduction-settings.index');
        Route::put('payroll-deduction-settings', [PayrollDeductionSettingController::class, 'update'])->name('payroll-deduction-settings.update');
        Route::put('payroll-deduction-settings/shifts/{shift}', [PayrollDeductionSettingController::class, 'updateShift'])->name('payroll-deduction-settings.shifts.update');
    });

    Route::get('master-data', [MasterDataController::class, 'index'])->name('master-data.index');
    Route::post('master-data/departments', [MasterDataController::class, 'storeDepartment'])->name('master-data.departments.store');
    Route::delete('master-data/departments/{department}', [MasterDataController::class, 'destroyDepartment'])->name('master-data.departments.destroy');
    Route::post('master-data/positions', [MasterDataController::class, 'storePosition'])->name('master-data.positions.store');
    Route::delete('master-data/positions/{position}', [MasterDataController::class, 'destroyPosition'])->name('master-data.positions.destroy');

    Route::get('work-calendar', [WorkCalendarController::class, 'index'])->name('work-calendar.index');
    Route::put('work-calendar', [WorkCalendarController::class, 'update'])->name('work-calendar.update');
    Route::post('work-calendar/holidays', [WorkCalendarController::class, 'storeHoliday'])->name('work-calendar.holidays.store');
    Route::delete('work-calendar/holidays/{holiday}', [WorkCalendarController::class, 'destroyHoliday'])->name('work-calendar.holidays.destroy');

    Route::middleware(EnsureShiftFeatureEnabled::class)->group(function () {
        Route::get('shifts', [ShiftController::class, 'index'])->name('shifts.index');
        Route::post('shifts', [ShiftController::class, 'store'])->name('shifts.store');
        Route::put('shifts/{shift}', [ShiftController::class, 'update'])->name('shifts.update');
        Route::delete('shifts/{shift}', [ShiftController::class, 'destroy'])->name('shifts.destroy');
    });

    Route::get('attendance-settings', [AttendanceSettingController::class, 'index'])->name('attendance-settings.index');
    Route::put('attendance-settings/office-hours', [AttendanceSettingController::class, 'updateHours'])->name('attendance-settings.hours.update');
    Route::put('attendance-settings/office-location', [AttendanceSettingController::class, 'updateLocation'])->name('attendance-settings.location.update');
    Route::put('attendance-settings/break', [AttendanceSettingController::class, 'updateBreak'])->name('attendance-settings.break.update');

    Route::get('feature-settings', [FeatureSettingController::class, 'index'])->name('feature-settings.index');
    Route::put('feature-settings', [FeatureSettingController::class, 'update'])->name('feature-settings.update');
});

require __DIR__.'/settings.php';
