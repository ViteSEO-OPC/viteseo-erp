<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RC3 Phase 3 — Reminder Automation. Append-only delivery-history for Planner-event reminders, mirroring
 * `email_events`. Delivery metadata belongs to the DELIVERY subsystem — NOT the Planner table, which
 * stays immutable as the single scheduling authority. `unique(planner_event_id, channel)` makes delivery
 * idempotent and extends cleanly to multi-channel (database → email/sms/slack/teams/whatsapp/n8n) with no
 * Planner change. The reminder's "when" is always DERIVED from Planner (`starts_at − reminder_minutes`);
 * this table records only that a reminder was delivered — it is not a schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planner_event_id')->constrained('crm_planner_events')->cascadeOnDelete();
            $table->string('channel');              // database (RC3) | email | sms | slack | teams | whatsapp | n8n (future)
            $table->timestamp('delivered_at');
            $table->json('meta')->nullable();       // recipient / notification id / channel-specific detail

            // Idempotency: one delivery per (event, channel) — the dispatcher firstOrCreate's on this.
            $table->unique(['planner_event_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_reminder_deliveries');
    }
};
