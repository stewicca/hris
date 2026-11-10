<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('employee_schedules');
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_schedules')) {
            Schema::create('employee_schedules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
                $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
                $table->date('date');
                $table->timestamps();

                $table->unique(['employee_id', 'date']);
            });
        }
    }
};
