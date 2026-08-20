<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Government identifiers for the payslip header. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->string('tin')->nullable()->after('philhealth_contribution');
            $table->string('sss_number')->nullable()->after('tin');
            $table->string('philhealth_number')->nullable()->after('sss_number');
            $table->string('pagibig_number')->nullable()->after('philhealth_number');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn(['tin', 'sss_number', 'philhealth_number', 'pagibig_number']);
        });
    }
};
