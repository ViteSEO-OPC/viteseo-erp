<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Slice 1, step 4b (second half). Records the attendance policy AS IT STOOD when the run
// executed — the divisor, the deduction modes, the rounding rule, the cap.
//
// Why: unpaidDaysFor() reads the LIVE, mutable policy singleton. Flip a mode in September,
// re-run July, and July's payslips silently reprice. With a deduction on every late arrival
// that becomes a dispute nobody can settle. The snapshot is the answer to "which rules
// produced this payslip?".
//
// Nullable: existing runs have no snapshot, and that is honest — they predate the feature.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->json('policy_snapshot')->nullable()->after('run_by');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->dropColumn('policy_snapshot');
        });
    }
};
