<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One on-demand discovery run — the Stage 2 execution record.
 *
 * ADDITIVE. The existing pooling path (GET /nearby-clients), the curated rotation (`discover-all`
 * over discovery-targets.json) and the nightly/manual scraper invocations are untouched and remain
 * the default behaviour. Nothing reads this table unless a user explicitly starts a run.
 *
 * WHY A TABLE RATHER THAN THE CACHE. The comparable async record in this module
 * ({@see \App\Modules\Crm\Services\Import\XlsImportJobStore}) is deliberately cache-backed because a
 * spreadsheet conversion is cheap, private and disposable. A discovery run is none of those: it
 * SPENDS MONEY at a paid provider, and the question "who ran what, against which provider, and what
 * did it cost us" has to be answerable after the hour a cache entry would survive. That audit
 * requirement is what buys the schema change.
 *
 * `uuid` is what the API exposes, never `id` — run ids must not be enumerable, matching how the
 * import poller hides its job ids.
 *
 * `scope_class` distinguishes the three scopes that must never silently become each other:
 *   - `curated`   the version-controlled rotation (class A) — NOT used by this path, reserved
 *   - `scoped`    explicit user-chosen areas and niches (class B) — what Phase 1 implements
 *   - `nationwide` deterministic PH-wide expansion (class C) — Phase 4
 * It exists from the first migration precisely so `scoped` can never be mistaken for `nationwide`.
 *
 * PROVIDER HONESTY. `provider_requested` is what configuration asked for; `provider_effective` is
 * what actually ran. When a credential is missing, disabled or REJECTED mid-run, `degraded_reason`
 * and `provider_degraded_at` record why, and the per-target rows record which provider answered each
 * individual task. The system must never present OpenStreetMap results as Google's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_discovery_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->uuid('uuid');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            // curated | scoped | nationwide
            $table->string('scope_class', 20)->default('scoped');
            // pending | running | completed | failed | cancelled
            $table->string('status', 20)->default('pending');
            // planning | discovering | finalizing — human-facing progress phase, nullable when idle.
            $table->string('phase', 30)->nullable();

            // What was asked for, verbatim: {locations: [...], niches: [...], limitPerTask: n}.
            // Free text on both axes — there is no city list and no niche whitelist anywhere.
            $table->json('scope');

            $table->string('provider_requested', 30)->nullable();
            $table->string('provider_effective', 30)->nullable();
            $table->string('provider_reason', 60)->nullable();
            $table->string('provider_mode', 20)->nullable();
            $table->timestamp('provider_degraded_at')->nullable();
            $table->string('degraded_reason', 60)->nullable();

            $table->unsignedInteger('targets_total')->default(0);
            $table->unsignedInteger('targets_completed')->default(0);
            $table->unsignedInteger('targets_failed')->default(0);

            // Rolled up from the per-target rows. `inserted` is what actually became CRM prospects;
            // `duplicates` is what the existing ingest recognised as already known.
            $table->unsignedInteger('discovered')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('rejected')->default(0);

            $table->text('error_message')->nullable();

            // Repeated clicks, a browser refresh and a network retry must not buy a second nationwide
            // run. Scoped per org because the key is client-supplied.
            $table->string('idempotency_key', 64)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique('uuid', 'crm_discovery_runs_uuid_unique');
            $table->unique(['org_id', 'idempotency_key'], 'crm_discovery_runs_org_idempotency_unique');
            $table->index(['org_id', 'status'], 'crm_discovery_runs_org_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_discovery_runs');
    }
};
