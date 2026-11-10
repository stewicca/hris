<?php

namespace App\Actions\Salaries;

use App\Models\Salary;

class MarkPayslipPaid
{
    public function handle(Salary $salary): Salary
    {
        abort_if($salary->status === 'paid', 403, 'Slip gaji ini sudah dibayar.');

        $salary->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        return $salary;
    }
}
