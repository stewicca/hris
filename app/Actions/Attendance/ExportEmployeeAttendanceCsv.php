<?php

namespace App\Actions\Attendance;

use App\Models\Attendance;
use App\Models\Employee;
use App\Support\AttendanceDeduction;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportEmployeeAttendanceCsv
{
    public function handle(Employee $employee): StreamedResponse
    {
        $attendances = $employee->attendances()->with('shift')->orderBy('date', 'desc')->get();
        $filename = 'kehadiran-'.Str::slug($employee->name).'-'.now()->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($attendances, $employee): void {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['Nama', 'No. Karyawan', 'Tanggal', 'Masuk', 'Pulang', 'Istirahat Mulai', 'Istirahat Selesai', 'Status', 'Potongan', 'Rincian Potongan']);

            foreach ($attendances as $a) {
                $deduction = AttendanceDeduction::for($a->setRelation('employee', $employee));

                fputcsv($handle, [
                    $employee->name,
                    $employee->employee_number,
                    $a->date->format('d/m/Y'),
                    $a->check_in ?? '-',
                    $a->check_out ?? '-',
                    $a->break_start ?? '-',
                    $a->break_end ?? '-',
                    Attendance::statusLabel($a->status),
                    $deduction->total,
                    $deduction->reason() ?: '-',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
