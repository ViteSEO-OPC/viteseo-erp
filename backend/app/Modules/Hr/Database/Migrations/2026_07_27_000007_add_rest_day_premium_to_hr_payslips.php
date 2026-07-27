<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// RD-1: pay for WORKING a scheduled rest day — the first 8 hours at the rest-day rate. Folded into
// gross like holiday_premium / overtime_pay / night_diff_pay. Hours past the 8th are rest-day
// OVERTIME instead (priced by overtimePayFor), so a rest day is never paid twice.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->decimal('rest_day_premium', 12, 2)->default(0)->after('night_diff_minutes');
            $table->unsignedInteger('rest_day_minutes')->default(0)->after('rest_day_premium');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn(['rest_day_premium', 'rest_day_minutes']);
        });
    }
};
