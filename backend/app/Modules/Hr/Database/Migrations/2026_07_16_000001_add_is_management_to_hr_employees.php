<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Management employees are salaried on a FIXED basis — their pay is not prorated
// by attendance (absent/late/overtime/holiday premium) — and they are EXEMPT from
// clocking in/out (shown auto-present). This flag drives both behaviours.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->boolean('is_management')->default(false)->after('base_salary');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn('is_management');
        });
    }
};
