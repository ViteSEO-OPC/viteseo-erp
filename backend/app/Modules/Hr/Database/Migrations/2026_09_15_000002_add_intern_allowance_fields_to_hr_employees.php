<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An intern's own allowance, and the day they stopped being one.
 *
 * `allowance_amount` is an OVERRIDE of the company default in Intern Allowance settings, read in
 * the unit that settings page calculates in (per day, per hour or per month). Null means "the
 * company default". It is filled from the OJT requisition when a candidate is hired.
 *
 * `intern_converted_on` is the conversion's effective date. Payroll needs it because a cycle is
 * priced after the fact: a conversion dated inside a cycle must still price the days before it as
 * an internship, and by then the status column already says otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->decimal('allowance_amount', 12, 2)->nullable()->after('ojt_required_hours');
            $table->date('intern_converted_on')->nullable()->after('allowance_amount');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn(['allowance_amount', 'intern_converted_on']);
        });
    }
};
