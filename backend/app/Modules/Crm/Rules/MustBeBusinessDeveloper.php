<?php

namespace App\Modules\Crm\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A lead's `assigned_to` must reference a Business Development Manager.
 * Admin / Super Admin manage and monitor the pipeline but are never lead owners.
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
        }
    }
}
