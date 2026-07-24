<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_requests', function (Blueprint $table) {
            // Which balance bucket this request drew from at approval:
            // 'pool' | 'per_type' | 'none' (unpaid). Stamped on approve so cancel
            // reverses the exact bucket even if HR flips the global mode later.
            $table->string('deducted_from')->nullable()->after('cancel_reason');
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_requests', function (Blueprint $table) {
            $table->dropColumn('deducted_from');
        });
    }
};
