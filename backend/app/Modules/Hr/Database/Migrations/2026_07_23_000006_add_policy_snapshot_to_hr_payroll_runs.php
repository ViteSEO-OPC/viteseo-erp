<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Step 4b (second half). Records the attendance policy AS IT STOOD when the run
// executed — the divisor, the deduction modes, the rounding rule, the cap.
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
