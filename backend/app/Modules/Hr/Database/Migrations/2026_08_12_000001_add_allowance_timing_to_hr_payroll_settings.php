<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** WHICH CUTOFF AN ALLOWANCE LANDS ON. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_settings', function (Blueprint $table) {
            $table->string('allowance_timing', 20)->default('every')->after('tax_timing');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_settings', function (Blueprint $table) {
            $table->dropColumn('allowance_timing');
        });
    }
};
