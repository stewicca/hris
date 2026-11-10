<?php

namespace App\Actions\Leaves;

use App\Models\Leave;
use App\Models\User;
use App\Notifications\EmployeeNotification;

class ApproveLeave
{
    public function handle(Leave $leave, User $actor): Leave
    {
        abort_unless($leave->isPending(), 403, 'Cuti ini sudah diproses.');

        $leave->update([
            'status' => 'approved',
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        $leave->employee->user?->notify(new EmployeeNotification(
            'Cuti Disetujui',
            "Pengajuan cuti {$leave->days} hari ({$leave->start_date->format('d M Y')} - {$leave->end_date->format('d M Y')}) telah disetujui.",
            'leave',
        ));

        return $leave;
    }
}
