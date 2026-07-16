<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// HR controls WHEN within a month statutory deductions and withholding tax are
// taken: every period (split), only the first period, or only the last period.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_settings', function (Blueprint $table) {
            $table->string('deduction_timing')->default('every')->after('frequency'); // every|first|last
            $table->string('tax_timing')->default('every')->after('deduction_timing'); // every|first|last
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_settings', function (Blueprint $table) {
            $table->dropColumn(['deduction_timing', 'tax_timing']);
        });
    }
};
