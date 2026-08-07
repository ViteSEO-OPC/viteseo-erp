<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice B19 — the payslip records what each contribution was actually charged on.
 *
 * WHY IT HAS TO BE STORED. Until this slice the answer was derivable and dull: every contribution
 * was charged on `hr_employees.base_salary`, so anyone could look it up. Two changes in this slice
 * end that. The base can now be the period's actual earnings, which depends on attendance, four
 * premium lines and the employment window; and the allowances inside it depend on a per-programme
 * `contributable` flag that HR can change. Reconstructing "what was SSS charged on in August" from
 * a stored payslip afterwards is no longer possible, and it is the first question anyone asks when
 * a remittance is queried.
 *
 * Same reasoning as `allowances_breakdown` and the run's `policy_snapshot`: what cannot be
 * re-derived is snapshot at the moment it is computed.
 *
 * KEYED BY TYPE because the three no longer share one number — SSS on earnings and PhilHealth on
 * basic is the recommended configuration, not an edge case. Written only for the types the employee
 * is enrolled in; null on every payslip computed before this slice, and on the 13th-month, final-pay
 * and adjustment runs, which take no statutory contributions at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->json('contribution_bases')->nullable()->after('contribution_overrides');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn('contribution_bases');
        });
    }
};
