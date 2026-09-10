<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services;

use App\Support\AccessLifecycle;
use App\Models\User;
use App\Modules\Crm\Models\CrmUserAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single place CRM (Zerem) access is granted, changed, or read off a centralized user.
 *
 * The platform has ONE `users` table shared by TeamOra (HRIS), Zerem (CRM) and Sprintly (Projects),
 * and per-module Spatie roles that are deliberately independent of one another — a person can be a
 * CRM admin and a plain HRIS employee (see RolesAndPermissionsSeeder's "Per-system roles" block).
 * Granting someone CRM access is therefore NOT an account-creation problem: it is one additive row
 * in `model_has_roles` against the account they already have.
 *
 * Two invariants this class exists to enforce, both of which the old inline
 * `$user->syncRoles([$role])` broke:
 *
 *  1. **Nothing outside `model_has_roles` (and `crm_user_access`) is written.** No method here calls
 *     `save()` on the user, so a grant cannot touch the password, name, email, status, or any
 *     profile column. That is what makes granting CRM access to a live HRIS account safe — and it
 *     is why {@see self::suspend()} lives here rather than in a controller: suspension is bound by
 *     the same rule, and records to a CRM-owned table instead of the shared `users` row.
 *  2. **Only CRM roles are ever removed.** {@see self::MANAGED_ROLES} is the complete set this class
 *     may detach. `hr_*` and `pm_*` roles are invisible to it, so changing someone's CRM role can
 *     never revoke their HRIS or Projects access.
 *
 * `super_admin` is intentionally NOT managed: it is the platform-wide owner role, not a CRM role, so
 * it is never assigned or removed here even though it outranks both CRM roles for display purposes.
 */
class CrmAccessService
{
    /**
     * The ONLY roles this service may remove. Changing a user's CRM role detaches the other entry in
     * this list and nothing else — every `hr_*` / `pm_*` / `super_admin` role survives untouched.
     *
     * @var list<string>
     */
    public const MANAGED_ROLES = ['crm_admin', 'crm_bd'];

    /**
     * Display precedence when a user holds more than one CRM-relevant role. Includes `super_admin`
     * (which outranks both) so an owner account renders as "Super Admin" rather than as whichever
     * role the pivot join happened to return first — but note it is absent from
     * {@see self::MANAGED_ROLES}, so it is display-only and never written.
     *
     * @var array<string, int>
     */
    private const RANK = ['crm_bd' => 1, 'crm_admin' => 2, 'super_admin' => 3];

    /**
     * Grant CRM access to an EXISTING centralized user — the "existing user → Zerem" path.
     *
     * Additive by construction: Spatie's `assignRole()` attaches only the ids the user does not
     * already hold, so this is idempotent (a repeat grant is a no-op, not a duplicate pivot row) and
     * it never detaches anything. The user row is not written at all.
     */
    public function grant(User $user, string $role): User
    {
        $user->assignRole($role);

        return $user->load('roles');
    }

    /**
     * Set the user's CRM role, replacing ONLY their other CRM role. `null` means **no CRM access**:
     * every managed CRM role is removed and none is granted.
     *
     * This is the safe replacement for `syncRoles([$role])`, which synced against the user's ENTIRE
     * role set and so silently deleted `hr_employee` / `pm_member` from anyone edited through CRM
     * Team Accounts — locking them out of TeamOra and Sprintly. Here the detach list is bounded to
     * {@see self::MANAGED_ROLES}, so non-CRM roles are structurally out of reach on every path,
     * including the revoke one: taking someone's CRM access away must never cost them TeamOra or
     * Sprintly, and `super_admin` is not a managed role so an owner can never revoke their own way
     * out of the CRM either.
     */
    public function setCrmRole(User $user, ?string $role): User
    {
        foreach (self::MANAGED_ROLES as $managed) {
            if ($managed !== $role && $user->hasRole($managed)) {
                $user->removeRole($managed);
            }
        }

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user->load('roles');
    }

    /**
     * The user's highest-ranked CRM-relevant role, or null when they have no CRM access.
     *
     * Used for the `role` field of the CRM UserResource. The pivot has no ordering, so reading
     * "the first role" off a multi-module user returned whichever role id sorted lowest —
     * `hr_employee` (3) for a BD who is also an employee, which the CRM UI cannot render.
     */
    public function primaryCrmRole(User $user): ?string
    {
        $best = null;

        foreach ($user->getRoleNames() as $name) {
            if (! isset(self::RANK[$name])) {
                continue;
            }
            if ($best === null || self::RANK[$name] > self::RANK[$best]) {
                $best = $name;
            }
        }

        return $best;
    }

    /** Whether the user can already enter Zerem (holds any CRM-relevant role). */
    public function hasCrmAccess(User $user): bool
    {
        return $this->primaryCrmRole($user) !== null;
    }

    // --- CRM access suspension ----------------------------------------------
    //
    // Suspension is ACCESS CONTROL ONLY. Nothing below reads or writes a role, a lead, a Planner
    // event, a score, or any attribution column: a suspended BD keeps `crm_bd` (so an Admin can
    // still recognise the former owner in Team Accounts and hand their workload on), keeps every
    // lead and open Planner event, and keeps permanent credit for the work they completed. Moving a
    // resigned BD's workload is a separate, explicit Admin operation and is never triggered here.

    /**
     * Suspend the user's Zerem access. Idempotent — re-suspending an already-suspended user leaves
     * the original `suspended_at` and actor intact rather than restamping them, so the record keeps
     * saying when access was actually lost.
     *
     * The shared `users` row is not written, and neither is the shared Sanctum token: one token
     * serves TeamOra, Zerem and Sprintly, so revoking it here would sign the user out of products
     * this decision has nothing to do with. Losing Zerem is enforced per-request instead.
     */
    public function suspend(User $user, ?User $actor = null): void
    {
        if ($this->isSuspended($user)) {
            return;
        }

        $access = new CrmUserAccess([
            'suspended_at' => now(),
            'suspended_by' => $actor?->id,
        ]);
        // Associated rather than mass-assigned — `user_id` is the row's identity, never request data.
        $access->user()->associate($user);
        $access->save();

        // Their open CRM pages close now -- and if the CRM was all they had, they are signed out.
        AccessLifecycle::schedule($user, 'crm-suspended');
    }

    /**
     * Restore the user's Zerem access.
     *
     * Deleting the row is the whole operation. Because suspension never removed a role, never moved
     * a lead and never revoked a token, there is nothing to put back: the account they still had
     * simply passes the access check again, on the token they were already holding. Idempotent — a
     * restore on an active user is a no-op, not an error.
     */
    public function restore(User $user): void
    {
        CrmUserAccess::query()->where('user_id', $user->id)->delete();

        AccessLifecycle::schedule($user, 'crm-restored');
    }

    /**
     * Constrain a `users` query to accounts whose Zerem access is NOT suspended.
     *
     * The one definition every "active BDs" query shares, so the leaderboard, the ranking cohort and
     * the performance-target pickers cannot drift apart on what "active" means — they previously each
     * spelled `where('status', 'active')` against the SHARED users column, which was never a CRM
     * concept and never gated anything.
     *
     * Expressed as an anti-join against `crm_user_access` because absence of a row IS active: there
     * is no per-user flag to compare, and adding one would create a second source of truth.
     *
     * @param  Builder<\App\Models\User>  $query
     * @return Builder<\App\Models\User>
     */
    public function excludeSuspended(Builder $query): Builder
    {
        return $query->whereNotIn('users.id', CrmUserAccess::query()->select('user_id'));
    }

    /**
     * Whether the user's Zerem access is currently suspended.
     *
     * Absence of a row means active, which is why the 24 accounts that were never suspended need no
     * backfill and a brand-new user is usable immediately.
     */
    public function isSuspended(User $user): bool
    {
        return CrmUserAccess::query()->where('user_id', $user->id)->exists();
    }
}
