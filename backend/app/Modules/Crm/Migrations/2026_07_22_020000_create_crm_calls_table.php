<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Call Session detail records for the Calling Workspace. Planner-driven: `crm_calls` is a SUBORDINATE
 * detail record and can never exist without its Planner Event — `planner_event_id` is NOT NULL, so
 * Planner remains the single source of truth for the scheduled activity. One Planner Event may carry
 * MANY call attempts (1:many); the CallSessionService enforces at most one active (in_progress) call
 * per event. Every call also belongs to exactly one Lead and one BD (both required).
 *
 * Timeline stays Activity-driven: `call.started` / `call.completed` are logged to crm_activities (subject
 * = lead), and `planner-event.completed` is logged by the existing PlannerEventObserver. This table holds
 * only call-specific METADATA (duration, disposition, notes, and reserved provider/AI fields) — it never
 * duplicates business state that lives in Activities or on the Lead.
 *
 * Provider-agnostic: `provider` + `external_call_id` are vendor-neutral (manual today; Twilio / Teams /
 * Zoom / RingCentral / Aircall later, behind a CallProvider contract). Domain fields (status, outcome,
 * direction, provider) are `string` here and constrained by PHP backed enums (cast on the model) plus
 * FormRequest Rule::in — mirroring the leads/planner_events convention (business rules in the backend).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_calls', function (Blueprint $table) {
            $table->id();

            // The invariant: a call ALWAYS belongs to a Planner Event (Planner = authority). 1:many.
            $table->foreignId('planner_event_id')->constrained('crm_planner_events')->cascadeOnDelete();

            // Every call belongs to exactly one Lead and one BD (both required — enterprise decision).
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users'); // restrict on delete — preserve call attribution

            $table->string('direction')->default('outbound');   // outbound today; inbound future
            $table->string('provider')->default('manual');       // vendor-neutral: manual|twilio|teams|zoom|…

            // Lifecycle state machine (CallStatus enum): in_progress → completed.
            $table->string('status')->default('in_progress');
            // Disposition of a concluded call (CallOutcome enum): connected|no_answer|busy|voicemail|cancelled|failed.
            $table->string('outcome')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable(); // server-computed (ended − started)

            $table->text('notes')->nullable();
            $table->json('signals')->nullable(); // RESERVED — P3 business signals / P4 AI (unused in P1)

            // RESERVED provider/AI fields (vendor-neutral) — populated by future transports, no rework.
            $table->string('external_call_id')->nullable(); // the provider's call id (Twilio SID / Teams / …)
            $table->string('recording_url')->nullable();
            $table->longText('transcript')->nullable();
            $table->text('ai_summary')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['lead_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_calls');
    }
};
