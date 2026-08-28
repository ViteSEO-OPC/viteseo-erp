<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Rules\MustBeBusinessDeveloper;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /users/{user}/reassign-leads — hand a departing BD's whole portfolio to a replacement.
 *
 * `MustBeBusinessDeveloper` carries the destination rule: the recipient must hold `crm_bd` AND have
 * live Zerem access. That single rule is why a suspended BD — including the departing one — can never
 * be selected, without this request restating the check and risking drift from the other five call
 * sites that share it.
 */
class ReassignLeadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route + policy gate the action
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'to_user_id' => ['required', 'integer', 'exists:users,id', new MustBeBusinessDeveloper],
        ];
    }
}
