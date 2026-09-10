<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carry the CRM suspensions that already exist in `users.status` into `crm_user_access`.
 *
 * Without this step every currently-suspended BD would be silently reactivated the moment the read
 * path stops consulting `users.status` — a suspension lifting itself during a deploy is exactly the
 * failure this migration exists to prevent.
 *
 * SELECTION. `users.status = 'inactive'` AND the account holds a CRM role AND it is not
 * soft-deleted. The CRM-role condition is load-bearing: `users.status` lives on the shared row, so an
 * HRIS-only or Sprintly-only colleague could carry `inactive` for reasons that have nothing to do
 * with Zerem, and minting them a CRM suspension would invent a decision nobody made. A soft-deleted
 * account cannot sign in at all, so suspending it would be noise.
 *
 * The role list is hardcoded rather than read from {@see \App\Modules\Crm\Services\CrmAccessService}
 * MANAGED_ROLES. A migration is a record of what happened at a point in time; binding it to a live
 * constant would let a future edit silently rewrite history.
 *
 * `suspended_at` is set from `users.updated_at` — the closest available signal, and an APPROXIMATION.
 * True provenance is unrecoverable: nothing recorded when the status was last changed or by whom, so
 * `suspended_by` is null, meaning "pre-migration, actor unknown" rather than guessing an admin.
 *
 * `users.status` is deliberately NOT written, here or in down(). This migration only copies state
 * out of the shared row; removing CRM's footprint from that column is a separate decision.
 */
return new class extends Migration
{
    /** Frozen at the time of writing — see the class docblock on why this is not a live constant. */
    private const CRM_ROLES = ['crm_admin', 'crm_bd'];

    public function up(): void
    {
        $now = now();

        $suspended = DB::table('users')
            ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', \App\Models\User::class)
            ->whereIn('roles.name', self::CRM_ROLES)
            ->where('users.status', 'inactive')
            ->whereNull('users.deleted_at')
            ->distinct()
            ->pluck('users.updated_at', 'users.id');

        /*
         * The tenant, when this runs after multi-tenancy rather than before it.
         *
         * In a clean `migrate` this migration lands BEFORE add_org_id_to_crm_tables, so
         * `crm_user_access` has no org_id yet and that later migration's backfill claims
         * these rows for the first org. Run against today's schema instead — a targeted
         * re-run, or a database where this one was skipped — org_id is NOT NULL and the
         * insertOrIgnore below would swallow the violation and write NOTHING, silently
         * reactivating every suspended BD: the exact failure this migration exists to
         * prevent, in the exact shape it was written to avoid.
         */
        $orgId = Schema::hasColumn('crm_user_access', 'org_id')
            ? DB::table('orgs')->orderBy('id')->value('id')
            : null;

        foreach ($suspended as $userId => $updatedAt) {
            // insertOrIgnore, not insert: the unique constraint on user_id already forbids a second
            // row, and a re-run of this migration must be a no-op rather than an error.
            DB::table('crm_user_access')->insertOrIgnore(array_filter([
                'org_id' => $orgId,
                'user_id' => $userId,
                'suspended_at' => $updatedAt ?? $now,
                'suspended_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], static fn ($value): bool => $value !== null));
        }
    }

    public function down(): void
    {
        // Removes ONLY what up() could have inserted: a still-inactive CRM account with no recorded
        // actor. A suspension an admin performed after this migration carries their id in
        // `suspended_by` and is left alone, so a rollback cannot quietly restore someone's access.
        DB::table('crm_user_access')
            ->whereNull('suspended_by')
            ->whereIn('user_id', DB::table('users')
                ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_type', \App\Models\User::class)
                ->whereIn('roles.name', self::CRM_ROLES)
                ->where('users.status', 'inactive')
                ->select('users.id'))
            ->delete();
    }
};
