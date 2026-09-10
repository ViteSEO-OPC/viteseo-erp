<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE GLOBAL-ROLES DECISION. Ported from kps (2026_08_18_100000) on 2026-09-03,
 * because it is the keystone the rest of the tenancy work stands on and this repo
 * never had it.
 *
 * Spatie teams stays OFF.
 *
 * Roles are org-scoped because USERS are org-scoped: a user belongs to exactly
 * ONE org, ever — enforced here at the DB level. With that invariant, a global
 * `crm_admin` grant can never leak into another org, because its holder cannot
 * BE in another org. Every membership writer already creates fresh users
 * (TeamControllers enforce `unique:users,email`; onboarding/Kiosk mint new
 * accounts), so this index turns an existing convention into law.
 *
 * Trade-off accepted: one person serving two client orgs needs two accounts.
 * Upgrade path if that ever becomes a real customer need: enable Spatie teams
 * (org_id on model_has_roles + team-context middleware) and drop this index —
 * the two designs are mutually exclusive, so keep exactly one switched on.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Defensive: collapse any pre-existing multi-memberships before the
        // unique index lands — keep the default (login) org, else the oldest.
        $duplicateUserIds = DB::table('org_user')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id');

        foreach ($duplicateUserIds as $userId) {
            $keep = DB::table('org_user')
                ->where('user_id', $userId)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->value('id');

            DB::table('org_user')
                ->where('user_id', $userId)
                ->where('id', '!=', $keep)
                ->delete();
        }

        // Idempotent: this repo may already carry the index from a re-run.
        $existing = collect(Schema::getIndexes('org_user'))
            ->contains(fn (array $i): bool => $i['columns'] === ['user_id'] && $i['unique']);

        if (! $existing) {
            Schema::table('org_user', function (Blueprint $table) {
                $table->unique('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('org_user', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
        });
    }
};
