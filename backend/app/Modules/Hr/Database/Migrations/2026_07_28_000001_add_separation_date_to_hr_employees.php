<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 4 (2026-07-28 audit) — the employee's last day.
 *
 * Separation was recorded as `separation_type` plus a soft-delete, with `deleted_at`
 * standing in for the date. That made a final payslip impossible: payroll couldn't tell
 * WHEN someone left, and soft-deleted rows are invisible to `Employee::query()` anyway, so
 * anyone who separated mid-cycle simply vanished from the run they had worked.
 *
 * `deleted_at` is an audit timestamp, not a business date — HR can restore an employee, and
 * an accidental delete/restore would silently move someone's last working day. Hence an
 * explicit column, backfilled from the existing `deleted_at` for rows already separated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->date('separation_date')->nullable()->after('separation_type');
        });

        // Backfill: the soft-delete timestamp is the only record of the last day we have.
        // Only rows already marked as separations — never a plain soft-delete.
        DB::table('hr_employees')
            ->whereNotNull('separation_type')
            ->whereNotNull('deleted_at')
            ->update(['separation_date' => DB::raw('DATE(deleted_at)')]);
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn('separation_date');
        });
    }
};
