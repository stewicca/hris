<?php

namespace App\Actions\Leaves;

use App\Models\Employee;
use App\Models\Leave;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class SubmitLeave
{
    /**
     * @param  array{type: string, start_date: string, end_date: string, reason: string}  $validated
     */
    public function handle(Employee $employee, array $validated): Leave
    {
        abort_if($employee->status !== 'active', 403, 'Akun karyawan tidak aktif.');

        $start = Carbon::parse($validated['start_date'])->startOfDay();
        $end = Carbon::parse($validated['end_date'])->startOfDay();
        $days = (int) $start->diffInDays($end) + 1;

        $hasOverlap = $employee->leaves()
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->exists();

        if ($hasOverlap) {
            throw ValidationException::withMessages([
                'start_date' => 'Sudah ada pengajuan cuti pada rentang tanggal tersebut.',
            ]);
        }

        if ($validated['type'] === 'annual') {
            $remaining = $employee->annualLeaveSummary($start->year)['remaining'];

            if ($days > $remaining) {
                throw ValidationException::withMessages([
                    'end_date' => "Sisa cuti tahunan tidak cukup ({$remaining} hari tersisa).",
                ]);
            }
        }

        return $employee->leaves()->create([
            'type' => $validated['type'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'reason' => $validated['reason'],
            'days' => $days,
        ]);
    }
}
