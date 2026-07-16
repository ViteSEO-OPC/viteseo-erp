<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Lead Ownership Foundation — Phase 0 controlled backfill.
     *
     * Existing leads carry arbitrary factory-seeded `assigned_to` values (including
     * admins). Clear them so ownership starts from a clean, intentional state: Admin
     * distributes leads to BDs deliberately during testing. "Unassigned" is a temporary
     * operational state only — Phase 1 will require an assigned BD on create/import for
     * the production workflow.
     *
     * No index migration is needed: `assigned_to` is already indexed via its foreign
     * key (`leads_assigned_to_foreign`), which serves the `where assigned_to = ?` scope
     * and future `GROUP BY assigned_to` aggregations. Adding another index would be a
     * redundant duplicate.
     */
    public function up(): void
    {
        // Bypasses the model's soft-delete scope so every existing lead is reset.
        DB::table('crm_leads')->whereNotNull('assigned_to')->update(['assigned_to' => null]);
    }

    public function down(): void
    {
        // Irreversible: the previous (arbitrary) assignments are intentionally discarded.
    }
};
