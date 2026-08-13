<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Backfills the permissions introduced by the member-proposal, workload-analysis
 * and claim-pool features. They were only ever added to RolesAndPermissionsSeeder,
 * so an environment that deploys with `migrate` (and does not re-seed) has the
 * code but not the rows — and a permission with no row fails `can:` for every
 * role, super_admin included (there is no Gate::before bypass here).
 *
 * Deliberately ADDITIVE: it mirrors the seeder's grants with givePermissionTo()
 * rather than syncPermissions(), so a grant made by hand outside the seeder
 * survives — and super_admin, the one role spanning every module, keeps its HR
 * and CRM permissions untouched.
 */
return new class extends Migration
{
    /** Permission => roles that hold it, mirroring RolesAndPermissionsSeeder. */
    private const GRANTS = [
        'projects.tasks.propose' => ['pm_member', 'pm_apm', 'super_admin'],
        'projects.subtasks.propose' => ['pm_member', 'pm_apm', 'super_admin'],
        'projects.tasks.claim' => ['pm_member', 'pm_apm', 'super_admin'],
        'projects.workload.view' => ['pm_member', 'pm_apm', 'super_admin'],
        'projects.tasks.approve' => ['pm_apm', 'super_admin'],
        'projects.subtasks.approve' => ['pm_apm', 'super_admin'],
        'projects.workload.manage_capacity' => ['pm_apm', 'super_admin'],
    ];

    public function up(): void
    {
        // Clear first so the writes below are not reasoning off a stale map,
        // and again at the end so the app sees the new rows immediately rather
        // than after the cache TTL expires.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::GRANTS as $name => $roleNames) {
            $permission = Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);

            foreach ($roleNames as $roleName) {
                // Roles come from the seeder. If an environment somehow lacks
                // one, skip rather than invent it here — role creation is the
                // seeder's job, not this backfill's.
                $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

                if ($role !== null && ! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Only the role grants are withdrawn. The permission rows stay: this
        // migration cannot tell which ones it created versus which the seeder
        // had already put there, and deleting a live permission is worse than
        // leaving an unused row behind.
        foreach (self::GRANTS as $name => $roleNames) {
            $permission = Permission::where('name', $name)->where('guard_name', 'web')->first();

            if ($permission === null) {
                continue;
            }

            foreach ($roleNames as $roleName) {
                Role::where('name', $roleName)
                    ->where('guard_name', 'web')
                    ->first()
                    ?->revokePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
