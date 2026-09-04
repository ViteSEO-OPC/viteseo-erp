<?php

namespace App\Modules\Crm\Rules;

use App\Models\User;
use App\Modules\Crm\Services\CrmAccessService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A lead's `assigned_to` must reference a Business Development Manager **with live Zerem access**.
 * Admin / Super Admin manage and monitor the pipeline but are never lead owners.
 *
 * The suspension half is what stops a resignation handover from going nowhere: a suspended BD cannot
 * reach Zerem, so work assigned to them is work nobody can see or do — including reassigning a
 * departed BD's leads straight back to themselves. Enforced HERE rather than at each call site
 * because this one rule guards every path that can hand work to a BD: lead assign, lead create, lead
 * import, and both Planner event requests.
 */
class MustBeBusinessDeveloper implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return; // nullability is governed by the field's other rules
        }

        // Scoped to the current org: `users` is platform-wide, so another tenant's BD
        // would otherwise satisfy this rule and become the owner of this tenant's lead.
        $user = User::query()->inCurrentOrg()->find($value);

        if (! $user) {
            $fail('The selected user must be a Business Development Manager.');

            return;
        }

        // A lead is owned by somebody who works leads. That used to mean `crm_bd`,
        // because it was the only way to be one. A member on one of the org's own
        // roles holds no Spatie role at all, so an admin could tick lead
        // capabilities onto "Front Desk", put somebody in it, and then be refused
        // when assigning them a lead — the capability bought and the capability
        // usable disagreeing with each other.
        //
        // Admins are still refused, as before: this rule is about who CARRIES a
        // lead, not who may touch one.
        $carriesLeads = $user->hasRole('crm_bd')
            || (! $user->hasAnyRole(['crm_admin', 'super_admin']) && $user->hasPermissionTo('crm.leads.view'));

        if (! $carriesLeads) {
            $fail('The selected user must be a Business Development Manager.');

            return;
        }

        if (app(CrmAccessService::class)->isSuspended($user)) {
            $fail('The selected Business Development Manager’s Zerem access is suspended.');
        }
    }
}
