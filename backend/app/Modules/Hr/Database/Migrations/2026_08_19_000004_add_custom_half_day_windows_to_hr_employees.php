<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee AM/PM half-day windows, so the Job tab can override them like every other
 * schedule field.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'custom_am_start_time',
        'custom_am_end_time',
        'custom_pm_start_time',
        'custom_pm_end_time',
    ];

    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $after = 'custom_end_time';
            foreach (self::COLUMNS as $column) {
                $table->time($column)->nullable()->after($after);
                $after = $column;
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
