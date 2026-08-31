<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Source-of-truth timeline for a daily attendance record.
     *
     * Each event (check_in / break_start / break_end / check_out) carries its
     * own GPS + face audit, so a full daily history can be reconstructed.
     * `occurred_at` is a timestamp (not a time) to correctly handle night
     * shifts that cross midnight.
     */
    public function up(): void
    {
        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['check_in', 'break_start', 'break_end', 'check_out']);
            $table->timestamp('occurred_at');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('accuracy', 8, 2)->nullable();
            $table->string('photo_path')->nullable();
            $table->boolean('face_verified')->default(false);
            $table->string('notes')->nullable();

            // Set when an admin wrote this event by hand. Such an event carries
            // no GPS or photo, so the audit trail stays honest about which
            // times were actually clocked and which were filled in later.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['attendance_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_events');
    }
};
