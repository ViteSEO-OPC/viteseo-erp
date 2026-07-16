<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            // Premium pay for working on a (non-working) holiday — folded into gross.
            $table->decimal('holiday_premium', 12, 2)->default(0)->after('allowances');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn('holiday_premium');
        });
    }
};
