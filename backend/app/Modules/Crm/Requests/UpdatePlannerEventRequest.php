<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Enums\ConsultationOutcome;
use App\Modules\Crm\Rules\MustBeBusinessDeveloper;
use App\Support\OrgUserRule;
use Illuminate\Contracts\Validation\Validator;
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
            'event_type' => ['sometimes', 'required', Rule::in(['email', 'call', 'meeting', 'task', 'follow-up', 'consultation'])],
            'status' => ['sometimes', 'required', Rule::in(['todo', 'scheduled', 'in_progress', 'completed'])],
            // Business result — a separate axis from `status` (PDR-002 §4b), consultation-only.
            'outcome' => ['sometimes', 'nullable', Rule::in(ConsultationOutcome::values())],
            'priority' => ['sometimes', 'required', Rule::in(['low', 'medium', 'high'])],

            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'all_day' => ['sometimes', 'boolean'],

            'reminder_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],

            // Consultation's lead requirement is enforced in withValidator() against the RESULTING state,
            // not with requiredIf here: a Board drag patches `status` alone, and an implicit required rule
            // would reject that perfectly valid partial update.
            'lead_id' => ['sometimes', 'nullable', 'integer', 'exists:crm_leads,id'],
            'deal_id' => ['sometimes', 'nullable', 'integer', 'exists:crm_deals,id'],
            'company_id' => ['sometimes', 'nullable', 'integer'],
            'campaign_id' => ['sometimes', 'nullable', 'integer'],

            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],

            // Reassignment (Admin/SA typically). Must reference a Business Developer.
            'assigned_to' => ['sometimes', 'nullable', 'integer', OrgUserRule::exists(), new MustBeBusinessDeveloper],
        ];
    }

    /**
     * A consultation must always be linked to a lead (PDR-002 §8 Q3) — the Successful Consultations KPI
     * counts DISTINCT leads, so an unlinked consultation could never be counted and would be silently
     * invisible to reporting.
     *
     * Validated against the state the event would have AFTER this patch, which blocks both routes to a
     * lead-less consultation: nulling out an existing consultation's lead, and converting a lead-less
     * event into a consultation. A partial patch that touches neither field (the Board's lone `status`
     * drag) is unaffected.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $event = $this->route('plannerEvent');

            $resultingType = $this->input('event_type', $event?->event_type);
            if ($resultingType !== 'consultation') {
                return;
            }

            $resultingLeadId = $this->has('lead_id') ? $this->input('lead_id') : $event?->lead_id;
            if (blank($resultingLeadId)) {
                $validator->errors()->add('lead_id', 'A consultation must be linked to a lead.');
            }
        });

        // An outcome is consultation-only vocabulary, judged on the resulting type — so it cannot be
        // attached to another type, nor stranded on an event being converted away from a consultation.
        $validator->after(function (Validator $validator): void {
            $event = $this->route('plannerEvent');
            $resultingType = $this->input('event_type', $event?->event_type);
            $resultingOutcome = $this->has('outcome') ? $this->input('outcome') : $event?->outcome;

            if (filled($resultingOutcome) && $resultingType !== 'consultation') {
                $validator->errors()->add('outcome', 'Only a consultation can record an outcome.');
            }
        });
    }
}
