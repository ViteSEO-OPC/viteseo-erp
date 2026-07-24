<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The business RESULT of a concluded Planner event, kept strictly separate from its lifecycle
 * (🔒 PO decision 2026-07-24, PDR-002 §4b). `status` remains the lifecycle axis and is unchanged
 * (`todo · scheduled · in_progress · completed`); this column answers a different question — what
 * actually came of it.
 *
 * Merging the two was explicitly rejected: 10+ code paths read `status != 'completed'` as "still
 * pending", so a concluded-but-declined event modelled as a status would generate reminders forever,
 * count as missed indefinitely, and trigger escalation.
 *
 * `consultation` is the only event type that defines values today (successful · not_interested ·
 * no_show · cancelled — see ConsultationOutcome); every other type leaves it null. The column is
 * deliberately generic and NOT cast to a PHP enum on the model, matching how `status`/`event_type`
 * are already handled here, so a future event type can adopt its own vocabulary without a schema
 * change or a hydration failure on existing rows.
 *
 * Nullable with no backfill: events concluded before this column existed carry a genuine `null`
 * (result unknown) rather than a fabricated one — the same honesty rule as `completed_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_planner_events', function (Blueprint $table) {
            $table->string('outcome')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('crm_planner_events', function (Blueprint $table) {
            $table->dropColumn('outcome');
        });
    }
};
