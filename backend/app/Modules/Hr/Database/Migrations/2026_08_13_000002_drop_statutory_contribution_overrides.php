<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Drop the three per-employee statutory amounts. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn(['sss_contribution', 'pagibig_contribution', 'philhealth_contribution']);
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->decimal('sss_contribution', 12, 2)->nullable()->after('bank_account_name');
            $table->decimal('pagibig_contribution', 12, 2)->nullable()->after('sss_contribution');
            $table->decimal('philhealth_contribution', 12, 2)->nullable()->after('pagibig_contribution');
        });
    }
};
