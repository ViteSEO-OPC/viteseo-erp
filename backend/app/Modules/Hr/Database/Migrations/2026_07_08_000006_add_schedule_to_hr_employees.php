<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->foreignId('work_schedule_id')->nullable()->after('manager_id')
                ->constrained('hr_work_schedules')->nullOnDelete();
            $table->json('custom_workdays')->nullable()->after('work_schedule_id');
            $table->time('custom_start_time')->nullable()->after('custom_workdays');
            $table->time('custom_end_time')->nullable()->after('custom_start_time');
            $table->unsignedSmallInteger('custom_grace_minutes')->nullable()->after('custom_end_time');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_schedule_id');
            $table->dropColumn(['custom_workdays', 'custom_start_time', 'custom_end_time', 'custom_grace_minutes']);
        });
    }
};
