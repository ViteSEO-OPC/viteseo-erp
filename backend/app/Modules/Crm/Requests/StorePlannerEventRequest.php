<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Rules\MustBeBusinessDeveloper;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create-a-planner-event contract. Domain fields are constrained to their frozen value sets
 * (Rule::in) — the backend owns the defaults; the UI never invents them. status/priority are
 * `sometimes` (DB column defaults apply when omitted). Ownership mirrors leads: Admin/SA must
 * pick the owning BD; a BD creator is auto-assigned to self in the controller.
 */
class StorePlannerEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route authorizes: ->can('create', PlannerEvent::class) (calendar.create).
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            // `consultation` (PDR-002) is directly authorable, unlike `follow-up` — which the controller
            // still restricts to FollowUpScheduler so it keeps a single birth path.
            'event_type' => ['required', Rule::in(['email', 'call', 'meeting', 'task', 'follow-up', 'consultation'])],
            'status' => ['sometimes', Rule::in(['todo', 'scheduled', 'in_progress', 'completed'])],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high'])],

            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'all_day' => ['sometimes', 'boolean'],

            // Provenance (integration seam). A controlled domain value — an integrated consumer
            // such as Nearby Clients posts its own origin (e.g. 'nearby') so the event is
            // identifiable; defaults to 'manual' in the controller when omitted. Never free text.
            'source' => ['sometimes', Rule::in(['manual', 'nearby', 'outreach', 'system'])],

            // Persisted only; no notification delivery yet.
            'reminder_minutes' => ['nullable', 'integer', 'min:0'],

            // Relationship seams. lead_id/deal_id are validated against existing tables;
            // company_id/campaign_id are accepted but NOT exists-validated (no table yet).
            //
            // `lead_id` is REQUIRED for a consultation (PDR-002 §8 Q3): the Successful Consultations KPI
            // counts DISTINCT leads, so a consultation with no lead could never be counted and would be
            // silently invisible to reporting. Every other event type keeps it optional.
            'lead_id' => [
                Rule::requiredIf(fn (): bool => $this->input('event_type') === 'consultation'),
                'nullable', 'integer', 'exists:crm_leads,id',
            ],
            'deal_id' => ['nullable', 'integer', 'exists:crm_deals,id'],
            'company_id' => ['nullable', 'integer'],
            'campaign_id' => ['nullable', 'integer'],

            'description' => ['nullable', 'string', 'max:5000'],

            // Required for Admin/SA (they pick the owning BD); optional for a BD creator
            // (auto-assigned to self in the controller). Must reference a Business Developer.
            'assigned_to' => [
                Rule::requiredIf(fn (): bool => (bool) $this->user() && ! $this->user()->hasRole('crm_bd')),
                'nullable', 'integer', 'exists:users,id', new MustBeBusinessDeveloper,
            ],
        ];
    }
}
