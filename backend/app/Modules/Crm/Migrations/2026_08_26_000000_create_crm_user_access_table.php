<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM (Zerem) access state for a centralized user — the module-owned replacement for suspending a
 * colleague by writing `users.status`.
 *
 * WHY A SEPARATE TABLE AND NOT A COLUMN. The platform has ONE `users` table shared by TeamOra
 * (HRIS), Zerem (CRM) and Sprintly (Projects). Suspending a BD through `users.status` writes the
 * shared row, so the blast radius of a CRM-only decision is every product — the same class of defect
 * that made {@see \App\Modules\Crm\Services\CrmAccessService} bound its role writes to
 * `model_has_roles`. Keeping CRM access state in a `crm_`-prefixed table makes the isolation
 * structural: a CRM suspension physically cannot reach `users`, `hr_*`, or `pm_*`.
 *
 * ROW PRESENCE IS THE STATE. A row means "CRM access suspended"; no row means active. There is
 * deliberately no `status` column and no "active" row: a second representation of active is a second
 * thing that can drift out of agreement with the first. It also means the 24 unsuspended accounts
 * need no backfill, and a user who never had a row behaves correctly by default.
 *
 * WHAT SUSPENSION IS NOT. This table records access only. It never carries — and suspension never
 * writes — the user's CRM role, lead ownership, open Planner work, scores, or any attribution
 * column. Handing a resigned BD's workload to someone else is a separate, explicit Admin operation
 * and is never triggered from here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_user_access', function (Blueprint $table) {
            $table->id();

            // UNIQUE: a user is either suspended or not, so a second row could only ever be a
            // contradiction. The constraint — not application code — is what makes suspend()
            // idempotent.
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // Not nullable: the row's existence and this timestamp mean the same thing, so a null
            // here would describe a suspension that never happened.
            $table->timestamp('suspended_at');

            // NULLABLE and nullOnDelete on purpose. The acting admin may themselves be deleted
            // later, and that must never cascade into lifting somebody's suspension. Null reads as
            // "actor unknown" — which is also what the backfill records for pre-migration rows.
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_user_access');
    }
};
