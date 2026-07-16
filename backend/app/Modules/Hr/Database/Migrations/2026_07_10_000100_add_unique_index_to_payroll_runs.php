<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One payroll run per (cycle_start, cycle_end, frequency): stops a concurrent
// double-submit from creating duplicate runs for the same period. deleted_at is
// intentionally NOT part of the key — MySQL treats NULLs as distinct, which would
// let two live rows (both deleted_at=NULL) coexist and defeat the constraint. The
// app never soft-deletes runs (re-runs recompute in place), so this is safe.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->unique(
                ['cycle_start', 'cycle_end', 'frequency'],
                'hr_payroll_runs_period_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->dropUnique('hr_payroll_runs_period_unique');
        });
    }
};
