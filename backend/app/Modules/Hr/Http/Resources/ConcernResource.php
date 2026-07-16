<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Anonymity enforcement lives HERE (§14.3g), not in the UI: for an anonymous
 * concern the `employee` block is never emitted, on any endpoint, to anyone —
 * including the concern's own meetings. The reference_no is the only handle.
 */
class ConcernResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_no' => $this->reference_no,
            'category' => $this->category,
            'description' => $this->description,
            'is_anonymous' => $this->is_anonymous,
            'is_safety' => $this->category === 'safety',
            'status' => $this->status,
            'submitted_at' => $this->submitted_at,
            'resolved_at' => $this->resolved_at,
            // Identity ONLY for named concerns. Never for anonymous ones.
            'employee' => $this->is_anonymous ? null : [
                'id' => $this->employee_id,
                'name' => $this->whenLoaded('employee', fn () => $this->employee->user?->name),
            ],
            'meetings' => MeetingResource::collection($this->whenLoaded('meetings')),
            'created_at' => $this->created_at,
        ];
    }
}
