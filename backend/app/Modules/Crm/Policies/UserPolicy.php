<?php

namespace App\Modules\Crm\Policies;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Support\CurrentOrg;
use App\Models\CustomRole;

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
     * Role-scoped management: an Admin may act on the ordinary members of their own
     * org — never on another Admin or a Super Admin, which is what stops this being
     * a privilege escalation.
     *
     * "Ordinary member" used to mean `crm_bd`, because that was the only kind there
     * was. Custom roles added another: a member holding one has NO Spatie role at
     * all, only direct permissions, so the old check refused every action against
     * them. The list screen had the same assumption and showed an empty table; that
     * was fixed and this was left, so an admin could finally SEE the person they
     * had hired and still got 403 renaming, suspending or reassigning them.
     *
     * Deliberately NOT widened to everyone in the org: a colleague with no CRM
     * footing at all — an HR-only employee — appears on the merged roster because
     * it lists membership, and a CRM admin has no business renaming them.
     */
    private function canManage(User $actor, User $target): bool
    {
        if (! $actor->hasRole('crm_admin')) {
            return false;
        }

        if ($target->hasAnyRole(['crm_admin', 'super_admin'])) {
            return false;
        }

        if ($target->hasRole('crm_bd')) {
            return true;
        }

        // One of this org's own roles. The org scope on CustomRole is what keeps
        // this from reaching into another tenant's.
        $customRoleId = DB::table('org_user')
            ->where('org_id', CurrentOrg::id())
            ->where('user_id', $target->id)
            ->value('custom_role_id');

        return $customRoleId !== null
            && CustomRole::query()->whereKey($customRoleId)->exists();
    }
}
