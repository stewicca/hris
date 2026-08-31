<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bring an already-migrated database up to the shape the create migrations
     * now describe.
     *
     * Up to this point new columns were folded into the original create
     * migrations, which is right while every database can be rebuilt with
     * migrate:fresh. Production has since been carrying real data for weeks, so
     * those edits reach it only through a migration of its own. Four things
     * drifted: the deduction ladders on a shift, the admin who recorded an
     * attendance row or event by hand, and the two statuses a hand-recorded day
     * can carry.
     *
     * Every step is guarded, so against a database built from the current
     * create migrations this whole file does nothing — which is what running it
     * on a fresh install and in CI has to mean.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('shifts', 'deduction_rules')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->json('deduction_rules')->nullable()->after('break_end');
            });
        }

        foreach (['attendances', 'attendance_events'] as $table) {
            if (! Schema::hasColumn($table, 'recorded_by')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    // Positioned where the create migration puts it, so a
                    // caught-up database and a fresh one stay byte-identical
                    // and a future schema diff still means something. MySQL
                    // honours this; SQLite ignores it, where order is moot.
                    $blueprint->foreignId('recorded_by')->nullable()->after('notes')
                        ->constrained('users')->nullOnDelete();
                });
            }
        }

        if (! $this->statusAlreadyWidened()) {
            Schema::table('attendances', function (Blueprint $table) {
                // Every attribute the column already had is restated: a change()
                // that omits one drops it.
                $table->enum('status', ['present', 'late', 'absent', 'sick', 'permit'])
                    ->default('present')
                    ->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['attendances', 'attendance_events'] as $table) {
            if (Schema::hasColumn($table, 'recorded_by')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropConstrainedForeignId('recorded_by');
                });
            }
        }

        if (Schema::hasColumn('shifts', 'deduction_rules')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->dropColumn('deduction_rules');
            });
        }

        // The status enum is deliberately left wide. Narrowing it would blank
        // every sick and permit row already recorded, and a wider enum costs
        // nothing — this is the one step that is not worth reversing.
    }

    /**
     * Whether the status column already accepts the two hand-recorded statuses.
     *
     * Asked of the database rather than assumed, because the answer differs by
     * driver: MySQL holds an enum, while SQLite renders one as a varchar with a
     * check constraint. An unrecognised driver answers no, so the portable
     * change() runs rather than being silently skipped.
     */
    private function statusAlreadyWidened(): bool
    {
        $connection = Schema::getConnection();

        $definition = match ($connection->getDriverName()) {
            'sqlite' => $connection->scalar(
                "select sql from sqlite_master where type = 'table' and name = 'attendances'",
            ),
            'mysql', 'mariadb' => $connection->scalar(
                'select column_type from information_schema.columns
                 where table_schema = database() and table_name = ? and column_name = ?',
                ['attendances', 'status'],
            ),
            default => null,
        };

        return str_contains((string) $definition, "'sick'");
    }
};
