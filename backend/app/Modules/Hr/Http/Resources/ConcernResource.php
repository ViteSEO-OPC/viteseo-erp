<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Anonymity policy (§14.3g) is enforced HERE, not in the UI. An anonymous concern
 * stays anonymous to everyone EXCEPT HR: HR may see the submitter to deter jokes and
 * spam, and is responsible for keeping that identity private. For any non-HR viewer,
 * the `employee` block is never emitted for an anonymous concern — the reference_no is
 * the only handle. `is_anonymous` is always sent so the UI can flag "keep private".
 */
class ConcernResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewerIsHr = (bool) $request->user()?->hasRole('hr_officer');
        $revealIdentity = ! $this->is_anonymous || $viewerIsHr;

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
            // Named concerns: always. Anonymous: HR only (must keep it private).
            'employee' => $revealIdentity ? [
                'id' => $this->employee_id,
                'name' => $this->whenLoaded('employee', fn () => $this->employee->user?->name),
            ] : null,
            'meetings' => MeetingResource::collection($this->whenLoaded('meetings')),
            'created_at' => $this->created_at,
        ];
    }
}
