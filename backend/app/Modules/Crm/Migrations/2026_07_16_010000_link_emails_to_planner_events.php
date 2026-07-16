<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RC3 Planner ↔ Outreach scheduling link (additive refinement). A nullable FK from an outbound email
 * to the Planner "Email Task" event that owns its schedule. Standalone Send-Later emails leave this NULL
 * and keep using `emails.scheduled_at`; Planner-linked emails derive their execution schedule from the
 * linked event's `starts_at` (no second editable scheduling value). `nullOnDelete` keeps sent history if
 * the event is later removed. The `planner_events` table is NOT modified (Planner ownership untouched).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->foreignId('planner_event_id')
                ->nullable()
                ->after('lead_id')
                ->constrained('crm_planner_events')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->dropConstrainedForeignId('planner_event_id');
        });
    }
};
