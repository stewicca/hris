<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named work-shift definitions (e.g. "Pagi 08-17", "Malam 22-06").
     *
     * Each shift owns its own office hours, late threshold, grace period and
     * optional break window. Employees reference a default shift via
     * employees.shift_id; per-date overrides live in employee_schedules.
     */
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->time('check_in');
            $table->time('check_out');
            $table->time('late_threshold');
            $table->unsignedSmallInteger('grace_minutes')->default(0);
            $table->boolean('break_enabled')->default(false);
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
