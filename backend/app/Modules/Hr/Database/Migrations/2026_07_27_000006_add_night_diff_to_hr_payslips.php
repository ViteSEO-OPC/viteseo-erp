<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ND-1: night-shift differential — a positive pay line for work between 22:00 and 06:00, folded
// into gross like holiday_premium / overtime_pay. Default 0; a payslip only carries an amount when
// the employee actually clocked night hours.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->decimal('night_diff_pay', 12, 2)->default(0)->after('overtime_minutes');
            $table->unsignedInteger('night_diff_minutes')->default(0)->after('night_diff_pay');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn(['night_diff_pay', 'night_diff_minutes']);
        });
    }
};
