<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create-a-follow-up-for-a-lead contract (Lead Details "Schedule follow-up" Quick Action). Every
 * field is optional — the minimal action is a bare click that creates an undated To-Do follow-up;
 * a BD may also pass a date/priority/reminder/note. `event_type`, `status`, ownership, and
 * provenance are decided server-side (in the controller + FollowUpScheduler), never by the client.
 */
class StoreLeadFollowUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route authorizes: permission:follow-ups.create + ->can('view', 'lead') (ownership).
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'reminder_minutes' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
