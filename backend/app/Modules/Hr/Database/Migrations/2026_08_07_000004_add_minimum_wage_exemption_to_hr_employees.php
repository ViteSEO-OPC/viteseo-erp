<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-030b — "this person is legitimately below the floor, and here is why".
 *
 * WHY THE FLAG HAS TO EXIST. The minimum-wage check compares a DERIVED daily rate against one
 * company-wide floor, and there are real arrangements it cannot see: part-time schedules, a
 * granted BMBE or distressed-establishment exemption, apprentices under a separate order. Without
 * somewhere to record that, those people are flagged forever, and a warning that is permanently
 * wrong for the same five names is a warning everyone learns to scroll past — which is how the
 * one genuine breach gets missed.
 *
 * The reason is stored, not just the boolean, because "exempt" with no explanation is the same
 * dead end the retro-flag note (FIN-009) refused to accept. If it is ever questioned, the answer
 * has to be on the record rather than in somebody's memory.
 *
 * Defaults to false: nobody is exempt until a person says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->boolean('minimum_wage_exempt')->default(false)->after('is_management');
            $table->string('minimum_wage_exempt_reason')->nullable()->after('minimum_wage_exempt');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn(['minimum_wage_exempt', 'minimum_wage_exempt_reason']);
        });
    }
};
