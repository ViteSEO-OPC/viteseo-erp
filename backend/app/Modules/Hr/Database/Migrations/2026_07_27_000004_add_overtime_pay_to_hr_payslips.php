<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// OT-1: payslip columns for the overtime pay line — folded into gross by OT-3, exactly like
// holiday_premium. Default 0, so no-op until an approved OT request is priced (Phase 1, OT-3).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->decimal('overtime_pay', 12, 2)->default(0)->after('holiday_premium');
            $table->unsignedInteger('overtime_minutes')->default(0)->after('overtime_pay');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn(['overtime_pay', 'overtime_minutes']);
        });
    }
};
