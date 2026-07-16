<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planner scheduling records (Planner module — evolution of the legacy Calendar). The single
 * scheduling entity for every CRM module. Named `planner_events` (model PlannerEvent) — NOT a
 * generic `events`/`Event` — to avoid collision with future Activity, Audit, Notification, and
 * System-Event concepts. Every write also logs an append-only `planner-event.*` Activity via
 * PlannerEventObserver, so the Planner flows through the existing Activity ownership/audit trail.
 *
 * Domain fields (event_type/status/priority/source) are `string` with backend defaults and are
 * constrained at the FormRequest layer (Rule::in) — mirroring the leads table convention, so the
 * enterprise business defaults live in the backend, not the UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_planner_events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            // UI type/legend color. One of: email, call, meeting, task, follow-up.
            $table->string('event_type');
            // Board column. One of: todo, scheduled, in_progress, completed.
            $table->string('status')->default('todo');
            // Card/sort hint. One of: low, medium, high.
            $table->string('priority')->default('medium');

            // Scheduling axis — independent of status. NULL starts_at = backlog/To-Do item that
            // appears only on the Board; a dated event also surfaces on the calendar views.
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('all_day')->default(false);

            // Persistence only — a future Notification Engine reads this. No delivery wired now.
            $table->unsignedInteger('reminder_minutes')->nullable();

            // Controlled provenance/domain value (extension seam). One of: manual, nearby,
            // outreach, system. Set server-side; the CRM never branches on it for behavior.
            $table->string('source')->default('manual');

            // Lead/contact linkage (Board card "lead · industry"). Leads are soft-deleted.
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->cascadeOnDelete();
            // Real FK — the deals table exists.
            $table->foreignId('deal_id')->nullable()->constrained('crm_deals')->nullOnDelete();

            // Future-ready relationship seams. The companies/campaigns tables do not exist yet,
            // so these are nullable indexed columns with NO FK constraint — promote to real FKs
            // when those modules land. Documented as reserved, not active relations.
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('campaign_id')->nullable()->index();

            // Ownership axis (owner avatar) — mirrors leads.assigned_to. The visibility boundary.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('description')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Common query paths: Board groups by status; calendar views range-scan starts_at.
            $table->index('status');
            $table->index('starts_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_planner_events');
    }
};
