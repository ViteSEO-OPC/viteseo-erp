<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 13th month pay becomes something payroll PAYS, not something a report mentions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->string('type', 20)->default('regular')->after('frequency');
        });

        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->decimal('thirteenth_month', 12, 2)->default(0)->after('rest_day_minutes');
            $table->decimal('other_benefits', 12, 2)->default(0)->after('thirteenth_month');
            $table->decimal('taxable_pay', 12, 2)->default(0)->after('other_benefits');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn(['thirteenth_month', 'other_benefits', 'taxable_pay']);
        });

        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
