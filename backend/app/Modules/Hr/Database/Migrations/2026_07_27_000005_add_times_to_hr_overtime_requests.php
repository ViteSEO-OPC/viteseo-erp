<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// An overtime request is filed as a TIME WINDOW (from → to); `hours` is derived from it
// server-side, never typed by the client. Nullable so any row filed before this (hours-only)
// still reads. A window crossing midnight (to <= from) counts as spanning into the next day.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_overtime_requests', function (Blueprint $table) {
            $table->time('time_from')->nullable()->after('work_date');
            $table->time('time_to')->nullable()->after('time_from');
        });
    }

    public function down(): void
    {
        Schema::table('hr_overtime_requests', function (Blueprint $table) {
            $table->dropColumn(['time_from', 'time_to']);
        });
    }
};
