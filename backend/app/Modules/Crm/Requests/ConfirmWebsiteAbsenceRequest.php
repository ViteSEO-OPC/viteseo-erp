<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A Business Development Manager confirming that no credible website exists for a lead.
 *
 * The note is optional but valuable: the strongest evidence is often something no crawler can obtain
 * ("called the shop, they confirmed they have no site"), and recording it turns the confirmation
 * into something a later reviewer can weigh rather than merely trust.
 */
class ConfirmWebsiteAbsenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route-level policy authorizes (the BD must be able to view the lead).
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
