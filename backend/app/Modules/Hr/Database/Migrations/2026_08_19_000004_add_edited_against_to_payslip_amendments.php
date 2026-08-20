<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** WHAT THE ENGINE SAID WHEN HR OVERRULED IT — kept so a re-run can report that it no longer says it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslip_amendments', function (Blueprint $table) {
            $table->decimal('edited_against', 12, 2)->nullable()->after('original_amount');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslip_amendments', function (Blueprint $table) {
            $table->dropColumn('edited_against');
        });
    }
};
