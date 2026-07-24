<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\PlannerEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API shape for a planner event — drives both the calendar pill (type + time) and the Board card
 * (type tag + title + lead·industry + owner avatar). `lead` and `owner` are included only when
 * eager-loaded (list/detail), shaped like LeadResource so the UI renders without extra requests.
 *
 * @mixin PlannerEvent
 */
class PlannerEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'eventType' => $this->event_type,
            'status' => $this->status,
            // Business result, separate from the lifecycle above. Null until concluded, and null for
            // every event type except `consultation` (ConsultationOutcome).
            'outcome' => $this->outcome,
            'priority' => $this->priority,

            'startsAt' => $this->starts_at?->toIso8601String(),
            'endsAt' => $this->ends_at?->toIso8601String(),
            'allDay' => (bool) $this->all_day,

            // Persisted only; a future Notification Engine consumes this.
            'reminderMinutes' => $this->reminder_minutes,
            // Controlled provenance (extension seam) — provider-agnostic; UI does not branch on it.
            'source' => $this->source,

            // Relationship seams. Ids are always present; the `lead`/`owner` objects only when loaded.
            'leadId' => $this->lead_id !== null ? (string) $this->lead_id : null,
            'lead' => $this->whenLoaded('lead', fn () => $this->lead ? [
                'id' => (string) $this->lead->id,
                'business' => $this->lead->business_name,
                'contact' => $this->lead->contact_name,
                'industry' => $this->lead->industry,
            ] : null),
            'dealId' => $this->deal_id !== null ? (string) $this->deal_id : null,
            'companyId' => $this->company_id !== null ? (string) $this->company_id : null,
            'campaignId' => $this->campaign_id !== null ? (string) $this->campaign_id : null,

            // Ownership: the assigned BD (owner avatar on the card).
            'assignedToId' => $this->assigned_to !== null ? (string) $this->assigned_to : null,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'id' => (string) $this->owner->id,
                'name' => $this->owner->name,
            ] : null),

            'description' => $this->description,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
