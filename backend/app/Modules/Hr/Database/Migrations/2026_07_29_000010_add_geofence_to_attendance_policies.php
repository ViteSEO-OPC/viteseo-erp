<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Geofenced clock-in (MVP). The office lives on the policy row rather than a new settings
// table because there is exactly ONE office in the MVP and Company → Rules already owns
// every clock-in rule — multi-office is an explicit non-goal.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->boolean('geofence_enabled')->default(false)->after('block_early_clock_in');
            // 7 decimal places ≈ 1.1 cm — far finer than any radius we'd enforce, and the
            // usual precision for a lat/lng pair.
            $table->decimal('office_lat', 10, 7)->nullable()->after('geofence_enabled');
            $table->decimal('office_lng', 10, 7)->nullable()->after('office_lat');
            $table->unsignedSmallInteger('office_radius_m')->default(200)->after('office_lng');
            // Exact-match list of the office's public IPs, e.g. ["203.0.113.10"]. CIDR ranges
            // are an explicit non-goal for the MVP.
            $table->json('office_ips')->nullable()->after('office_radius_m');
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn([
                'geofence_enabled',
                'office_lat',
                'office_lng',
                'office_radius_m',
                'office_ips',
            ]);
        });
    }
};
