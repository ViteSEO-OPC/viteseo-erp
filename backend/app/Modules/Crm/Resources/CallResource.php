<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\Call;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API shape for a call attempt — drives the Lead Details "Call History" list. Emits call METADATA only
 * (status / outcome / direction / timing / notes). The reserved provider + AI columns (external_call_id,
 * recording_url, transcript, ai_summary, signals) are intentionally NOT surfaced until a future transport
 * populates them. Enum fields serialize to their backing string; the frontend maps display labels
 * (mirrors PlannerEventResource emitting raw `status`). `agent` (the BD) is included only when eager-loaded.
 *
 * @mixin Call
 */
class CallResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'plannerEventId' => (string) $this->planner_event_id,
            'leadId' => (string) $this->lead_id,

            'direction' => $this->direction->value,
            'provider' => $this->provider,
            'status' => $this->status->value,
            'outcome' => $this->outcome?->value,

            'startedAt' => $this->started_at?->toIso8601String(),
            'endedAt' => $this->ended_at?->toIso8601String(),
            'durationSeconds' => $this->duration_seconds,

            'notes' => $this->notes,

            // The BD who placed the call — the object is present only when eager-loaded (list/detail).
            'agentId' => (string) $this->user_id,
            'agent' => $this->whenLoaded('agent', fn () => $this->agent ? [
                'id' => (string) $this->agent->id,
                'name' => $this->agent->name,
            ] : null),

            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
