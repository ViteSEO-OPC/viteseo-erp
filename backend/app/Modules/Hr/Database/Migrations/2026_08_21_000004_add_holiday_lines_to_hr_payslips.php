<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// WHICH holidays the `holiday_premium` figure was earned on. The payslip printed one lump sum
// with no note, so an employee who worked two holidays had no way to check it — and no way to
// see the rate applied or that a rest-day holiday paid the full rate rather than the add-on.
//
// Snapshot rather than derived, for the same reason `attendance_lines` is: reclassifying a
// holiday (or moving a rest day) after the run must not silently rewrite the explanation
// printed under a figure that has already been paid. Payslips written before this column
// existed keep NULL and go on showing the single total — deriving their lines today would
// answer with TODAY'S calendar, which is exactly the answer that must not be given.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->json('holiday_lines')->nullable()->after('holiday_premium');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn('holiday_lines');
        });
    }
};
