<?php

namespace App\Actions\Attendance;

use App\Models\Attendance;

class DeleteManualAttendance
{
    public function handle(Attendance $attendance): void
    {
        abort_unless(
            $attendance->isFullyManual(),
            403,
            'Hanya catatan yang dibuat manual oleh admin yang dapat dihapus.',
        );

        $attendance->delete();
    }
}
