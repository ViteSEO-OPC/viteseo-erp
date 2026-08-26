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

        $user = User::find($value);

        if (! $user || ! $user->hasRole('crm_bd')) {
            $fail('The selected user must be a Business Development Manager.');

            return;
        }

        if (app(CrmAccessService::class)->isSuspended($user)) {
            $fail('The selected Business Development Manager’s Zerem access is suspended.');
        }
    }
}
