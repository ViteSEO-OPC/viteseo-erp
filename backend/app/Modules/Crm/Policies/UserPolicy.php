<?php

namespace App\Modules\Crm\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Super Admin bypasses every check via Gate::before (AppServiceProvider), so the
     * methods below only ever run for Admin / BD Manager.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('crm.users.view');
    }

    public function view(User $user, User $target): bool
    {
        return $user->can('crm.users.view') && $this->canManage($user, $target);
    }

    public function create(User $user): bool
    {
        return $user->can('crm.users.create');
    }

    public function update(User $user, User $target): bool
    {
        return $user->can('crm.users.edit') && $this->canManage($user, $target);
    }

    public function delete(User $user, User $target): bool
    {
        // Only Super Admin holds users.delete (via the Gate::before bypass); Admin/BDM never do.
        return $user->can('crm.users.delete');
    }

    /**
     * Grant CRM access to an EXISTING centralized user (POST /users where the email already
     * resolves to an account).
     *
     * Separate from {@see self::update()} because `canManage()` requires the target to ALREADY be a
     * `crm_bd`, which is precisely what a grant target is not — an HRIS/Projects-only colleague has
     * no CRM role yet. The escalation guard is therefore inverted: rather than "must be a BD", it is
     * "must not outrank me". Combined with `assignableRoles()` capping an Admin's assignable set at
     * `crm_bd`, an Admin can neither mint another Admin nor modify one.
     *
     * Super Admin never reaches this method — Gate::before short-circuits it.
     */
    public function grantCrmAccess(User $user, User $target): bool
    {
        return $user->can('crm.users.create')
            && ! $target->hasAnyRole(['crm_admin', 'super_admin']);
    }

    /**
     * Role-scoped management: Admin may only act on BD Manager accounts — never on
     * another Admin or a Super Admin (prevents privilege escalation).
     */
    private function canManage(User $actor, User $target): bool
    {
        if ($actor->hasRole('crm_admin')) {
            return $target->hasRole('crm_bd')
                && ! $target->hasAnyRole(['crm_admin', 'super_admin']);
        }

        return false;
    }
}
