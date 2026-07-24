<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business Rules Administration — immutable audit trail (table 3 of 3; Implementation Design §1.3).
 *
 * One APPEND-ONLY row per configuration change, closing the current audit gap. Follows the existing
 * `crm_activities` pattern exactly: `created_at` only — NO `updated_at`, NO `deleted_at`. An audit entry,
 * once written, is a permanent part of history and is never updated or deleted (§1.3 / §1.6).
 *
 * `version_number` is a LOOSE reference (a plain integer), NOT a foreign key — mirroring
 * `crm_activities.subject_id`. This is deliberate: the audit trail must survive even if a draft it recorded
 * is later hard-discarded, so it must not be tied to a version row's lifetime (§1.3).
 *
 * `action` enum (§1.0): seed | edit | enable | disable | reorder | publish | rollback | discard_draft.
 * `create` / `delete` are reserved (Future Enhancement) and not valid in v1. Version-level actions
 * (publish, rollback, discard_draft, seed) leave `rule_key` null; rule-level actions set it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_business_rule_audit', function (Blueprint $table) {
            $table->id();

            $table->string('module');                 // module enum (§1.0).
            $table->string('action');                 // action enum (§1.0).
            $table->string('rule_key')->nullable();   // null for version-level actions.

            $table->text('previous_value')->nullable(); // value before the change.
            $table->text('new_value')->nullable();       // value after the change.

            // The version the action produced/affected — a loose integer reference, NOT a FK (§1.3).
            $table->unsignedInteger('version_number');

            $table->string('computation_spec_version'); // compatibility trail.
            $table->text('reason_for_change')->nullable();

            // Attribution to the shared users table; null for System actions. Null-on-delete preserves the
            // trail if the user is later removed.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Append-only: created_at ONLY — no updated_at (matches crm_activities; §1.3 / §1.6).
            $table->timestamp('created_at')->useCurrent();

            $table->index(['module', 'created_at']); // chronological/searchable history.
            $table->index('version_number');         // "all audit for version N".
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_business_rule_audit');
    }
};
