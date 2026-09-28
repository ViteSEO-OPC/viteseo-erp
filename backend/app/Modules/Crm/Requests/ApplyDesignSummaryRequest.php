<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What "Apply to all templates" would change, before it is confirmed. `email_design_id` is the design being
 * applied; absent for a design that is not saved yet.
 */
class ApplyDesignSummaryRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['email_design_id' => ['nullable', 'integer']];
    }
}
