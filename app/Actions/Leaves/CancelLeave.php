<?php

namespace App\Actions\Leaves;

use App\Models\Leave;

class CancelLeave
{
    public function handle(Leave $leave): void
    {
        abort_unless($leave->isPending(), 403, 'Hanya cuti yang masih menunggu yang dapat dibatalkan.');

        $leave->delete();
    }
}
