<?php

namespace App\Actions\Leaves;

use App\Models\Leave;
use App\Models\User;
use App\Notifications\EmployeeNotification;

class RejectLeave
{
    public function handle(Leave $leave, User $actor, string $reason): Leave
    {
        abort_unless($leave->isPending(), 403, 'Cuti ini sudah diproses.');

        $leave->update([
            'status' => 'rejected',
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $leave->employee->user?->notify(new EmployeeNotification(
            'Cuti Ditolak',
            "Pengajuan cuti Anda ditolak. Alasan: {$leave->rejection_reason}",
            'leave',
        ));

        return $leave;
    }
}
