<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DocsController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\SalaryController;
use App\Http\Middleware\EnsureLeaveFeatureEnabled;
use App\Http\Middleware\EnsurePayrollFeatureEnabled;
use Illuminate\Support\Facades\Route;

Route::get('/', DocsController::class);
Route::get('/status', fn () => response()->json(['status' => 'connected', 'version' => '1.0.0']));

Route::middleware(['web'])->group(function () {
    Route::get('/csrf-cookie', fn () => response()->noContent());
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:api-login');
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::middleware(['auth'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::put('/password', [AuthController::class, 'updatePassword']);

        Route::prefix('attendance')->group(function () {
            Route::get('/settings', [AttendanceController::class, 'settings']);
            Route::get('/today', [AttendanceController::class, 'today']);
            Route::post('/event', [AttendanceController::class, 'recordEvent'])->middleware('throttle:face');
            Route::get('/history', [AttendanceController::class, 'history']);
        });

        // Admin-only face enrollment (back-office capture flow).
        Route::post('/employees/{employee}/enroll-face', [EnrollmentController::class, 'store'])
            ->middleware('throttle:face');
        Route::delete('/employees/{employee}/enroll-face', [EnrollmentController::class, 'destroy']);

        Route::prefix('leaves')->middleware(EnsureLeaveFeatureEnabled::class)->group(function () {
            Route::get('/', [LeaveController::class, 'index']);
            Route::post('/', [LeaveController::class, 'store']);
        });

        Route::middleware(EnsurePayrollFeatureEnabled::class)->group(function () {
            Route::get('/salary', [SalaryController::class, 'index']);
            Route::get('/salary/{salary}/print', [SalaryController::class, 'print']);
        });

        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::post('/read-all', [NotificationController::class, 'markAllRead']);
            Route::post('/{id}/read', [NotificationController::class, 'markRead']);
        });
    });
});
