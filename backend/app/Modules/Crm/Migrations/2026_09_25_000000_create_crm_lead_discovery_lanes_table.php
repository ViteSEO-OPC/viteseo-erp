<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADDITIONAL DISCOVERY PROVENANCE, beside the lead rather than on it.
 *
 * `crm_leads.source` records the ONE lane that first found a business and is never rewritten. This
 * table records every further lane that found the same business — today only the AI-assisted lane
 * (`openai`), whether it rediscovered an existing lead or found it in the same search as the
 * OpenStreetMap baseline. It is written with the query builder only, so recording provenance never
 * touches `crm_leads`, never fires LeadObserver, never re-scores and never logs an activity.
 *
 * One row per (lead, lane): a rediscovery bumps `times_seen` and `last_seen_at`; `first_seen_at` and
 * `first_run_id` keep the first sighting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_discovery_lanes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->string('lane', 40);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('times_seen')->default(1);
            $table->foreignId('first_run_id')->nullable()->constrained('crm_discovery_runs')->nullOnDelete();
            $table->timestamps();

            $table->unique(['lead_id', 'lane']);
            $table->index(['org_id', 'lane']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_discovery_lanes');
    }
};
