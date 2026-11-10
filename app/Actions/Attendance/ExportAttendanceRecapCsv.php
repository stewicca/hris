<?php

namespace App\Actions\Attendance;

use App\Support\AttendanceRecap;
use Carbon\CarbonInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportAttendanceRecapCsv
{
    public function handle(CarbonInterface $start, CarbonInterface $end, ?string $department = null): StreamedResponse
    {
        $rows = AttendanceRecap::rows($start, $end, $department);
        $filename = 'rekap-kehadiran-'.$start->format('Ymd').'-'.$end->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, AttendanceRecap::COLUMNS);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['name'],
                    $row['employee_number'],
                    $row['department'] ?? '-',
                    $row['position'] ?? '-',
                    $row['working_days'],
                    $row['present'],
                    $row['late'],
                    $row['absent'],
                    $row['excused'],
                    $row['leave'],
                    $row['late_amount'],
                    $row['early_leave_amount'],
                    $row['break_overrun_amount'],
                    $row['absent_amount'],
                    $row['total_deduction'],
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
