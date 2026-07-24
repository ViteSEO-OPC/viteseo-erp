<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records WHEN a Planner event was completed — the real completion timestamp the Performance surfaces
 * need for month-bounded metrics (e.g. "Completed Follow-ups this month"). Deliberately NOT derived from
 * `updated_at` (any later edit would move it); it is stamped only on the todo/scheduled/in_progress →
 * completed transition by PlannerEventObserver, and cleared if an event is reopened.
 *
 * Nullable + no backfill: existing rows completed before this column existed carry a genuine `null`
 * (unknown completion time) and are correctly excluded from time-windowed completion counts, rather than
 * being assigned a fabricated/approximate date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_planner_events', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('crm_planner_events', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
