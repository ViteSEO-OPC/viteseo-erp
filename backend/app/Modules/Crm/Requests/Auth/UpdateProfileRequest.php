<?php

namespace App\Modules\Crm\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Self-service profile update. A user may edit ONLY their own name and phone.
 * Email (the login identity), role, and status are intentionally NOT accepted here — those
 * remain admin-only via the Team Accounts flow (StoreUserRequest / UpdateUserRequest).
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Always acts on the authenticated user only (see UpdateProfileController); no target id.
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ];
    }
}
