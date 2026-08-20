<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employer-only add-on (SSS's Employees' Compensation fund) belongs to the BAND,
 * not to a salary threshold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_contribution_brackets', function (Blueprint $table) {
            $table->decimal('employer_add_on', 12, 2)->nullable()->after('msc');
        });
    }

    public function down(): void
    {
        Schema::table('hr_contribution_brackets', function (Blueprint $table) {
            $table->dropColumn('employer_add_on');
        });
    }
};
