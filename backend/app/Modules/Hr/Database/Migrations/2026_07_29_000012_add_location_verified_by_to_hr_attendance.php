<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// HOW a clock-in proved where the employee was — the audit trail behind geofencing.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance', function (Blueprint $table) {
            // ip_gps | ip_only | remote_approved — see Attendance::LOCATION_* .
            $table->string('location_verified_by', 20)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance', function (Blueprint $table) {
            $table->dropColumn('location_verified_by');
        });
    }
};
