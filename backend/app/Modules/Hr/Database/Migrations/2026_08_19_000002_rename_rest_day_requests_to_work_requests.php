<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** `hr_rest_day_requests` becomes `hr_work_requests`, and grows a `type`. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('hr_rest_day_requests', 'hr_work_requests');

        Schema::table('hr_work_requests', function (Blueprint $table) {
            // rest_day | suspension
            $table->string('type', 20)->default('rest_day')->after('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('hr_work_requests', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        Schema::rename('hr_work_requests', 'hr_rest_day_requests');
    }
};
