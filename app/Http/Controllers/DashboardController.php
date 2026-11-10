<?php

namespace App\Http\Controllers;

use App\Actions\Attendance\BuildDailyAttendanceRoster;
use App\Models\Leave;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(BuildDailyAttendanceRoster $buildRoster): Response
    {
        $today = today();
        $roster = $buildRoster->handle($today);

        $leaveStats = [
            'pending' => Leave::query()->where('status', 'pending')->count(),
            'on_leave_today' => Leave::query()
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
                ->count(),
            'approved_this_month' => Leave::query()
                ->where('status', 'approved')
                ->whereMonth('start_date', $today->month)
                ->whereYear('start_date', $today->year)
                ->count(),
        ];

        return Inertia::render('dashboard', [
            'summary' => $roster['summary'],
            'date' => $today->toDateString(),
            'leaveStats' => $leaveStats,
        ]);
    }
}
