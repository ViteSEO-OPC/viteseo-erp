<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business Rules Administration — versioned configuration store (table 1 of 3).
 *
 * One row per version of a sub-module's ruleset (Implementation Design §1.1). A version is the unit of
 * publish/rollback: it moves through draft → active → archived (§1.4) and is NEVER edited in place once
 * published — every change produces a NEW version with the next monotonic `version_number` (§1.5).
 *
 * This table does NOT change the frozen computation: it only records WHERE the scoring engine's parameters
 * come from. Version 1 (seeded by the baseline migration) equals the frozen `config/scoring.php` values, so
 * switching the engine's source changes no score.
 *
 * Invariant — at most ONE `active` and at most ONE `draft` per `module` — is a SERVICE-level guarantee
 * (BusinessRuleService, pessimistic row-locking; §1.1 / §5.4), not a DB constraint: MySQL 8.4 has no partial
 * unique index. `state` / `module` are plain strings constrained by PHP backed enums, matching the
 * leads / planner_events / crm_calls convention (business rules live in the backend).
 *
 * Additive only — no existing table, column, or index is touched (§1.7 / §2.7). No soft delete: permanence
 * is expressed by the `archived` state, never by `deleted_at` (§1.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_business_rule_versions', function (Blueprint $table) {
            $table->id();

            // module enum (§1.0): lead_scoring (the only enabled module in v1) | kpi | ranking (reserved).
            $table->string('module');

            // Monotonic per module: strictly increasing, never reused — even after a discarded draft (§1.5).
            $table->unsignedInteger('version_number');

            // state enum (§1.0/§1.4): draft | active | archived. New versions start as draft.
            $table->string('state')->default('draft');

            // The frozen spec this version was created against — the compatibility record (e.g. v1.0.0).
            $table->string('computation_spec_version');

            // Optional business justification for the change this version represents.
            $table->text('reason_for_change')->nullable();

            // Attribution to the shared users table; null for the System-seeded baseline. Null-on-delete
            // preserves history if the user is later removed.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();

            $table->timestamps(); // No soft delete (§1.6).

            $table->unique(['module', 'version_number']);
            $table->index(['module', 'state']); // hot "active/draft for this module" lookup (§1.1).
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_business_rule_versions');
    }
};
