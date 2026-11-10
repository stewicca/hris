<?php

namespace App\Actions\Shifts;

use App\Models\Shift;

class DeleteShift
{
    public function handle(Shift $shift): bool
    {
        if ($shift->employees()->exists()) {
            return false;
        }

        $shift->delete();

        return true;
    }
}
