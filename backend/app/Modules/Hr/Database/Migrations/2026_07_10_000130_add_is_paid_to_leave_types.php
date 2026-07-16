<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Leave types gain a paid/unpaid flag. Paid leave doesn't reduce pay; unpaid leave
// (LWOP) is deducted at the daily rate by payroll (half-day = 0.5). Existing types
// default to paid.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table) {
            $table->boolean('is_paid')->default(true)->after('days_per_year');
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table) {
            $table->dropColumn('is_paid');
        });
    }
};
