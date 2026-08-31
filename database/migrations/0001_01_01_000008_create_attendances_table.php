<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // Snapshot of the shift that applied on this date, so historical
            // records stay accurate even if the master shift is later changed.
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');

            // Mirror columns (denormalized from attendance_events) kept for
            // cheap reads across existing views/exports. The attendance_events
            // table is the source of truth for the full timeline + per-event
            // GPS/face audit.
            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();

            // Legacy per-event GPS/face columns retained for backward
            // compatibility with existing reads; new audit data is written
            // per attendance_event.
            $table->decimal('check_in_lat', 10, 7)->nullable();
            $table->decimal('check_in_lng', 10, 7)->nullable();
            $table->decimal('check_in_accuracy', 8, 2)->nullable();
            $table->decimal('check_out_lat', 10, 7)->nullable();
            $table->decimal('check_out_lng', 10, 7)->nullable();
            $table->decimal('check_out_accuracy', 8, 2)->nullable();
            $table->string('check_in_photo_path')->nullable();
            $table->string('check_out_photo_path')->nullable();
            $table->boolean('face_verified')->default(false);

            $table->enum('status', ['present', 'late', 'absent', 'sick', 'permit'])->default('present');
            $table->string('notes')->nullable();

            // Set when an admin recorded or corrected this day by hand instead
            // of the employee clocking in. Null means the employee recorded it
            // themselves through the portal or the kiosk.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
