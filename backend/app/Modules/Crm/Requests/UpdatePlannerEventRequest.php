<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Rules\MustBeBusinessDeveloper;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial-update contract for a planner event — also the Board click-to-move path (a lone
 * `status` change). Every field is `sometimes` so any subset may be patched. Reassignment stays
 * BD-constrained; scheduling/domain values keep their frozen value sets.
 */
class UpdatePlannerEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route authorizes: ->can('update', 'plannerEvent') (calendar.edit + ownership).
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'event_type' => ['sometimes', 'required', Rule::in(['email', 'call', 'meeting', 'task', 'follow-up'])],
            'status' => ['sometimes', 'required', Rule::in(['todo', 'scheduled', 'in_progress', 'completed'])],
            'priority' => ['sometimes', 'required', Rule::in(['low', 'medium', 'high'])],

            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'all_day' => ['sometimes', 'boolean'],

            'reminder_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],

            'lead_id' => ['sometimes', 'nullable', 'integer', 'exists:crm_leads,id'],
            'deal_id' => ['sometimes', 'nullable', 'integer', 'exists:crm_deals,id'],
            'company_id' => ['sometimes', 'nullable', 'integer'],
            'campaign_id' => ['sometimes', 'nullable', 'integer'],

            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],

            // Reassignment (Admin/SA typically). Must reference a Business Developer.
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id', new MustBeBusinessDeveloper],
        ];
    }
}
