<?php

namespace App\Modules\Hr\Http\Resources;

use App\Modules\Hr\Services\PrivateFileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Anonymity is enforced HERE, not in the UI. */
class ConcernResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $revealIdentity = $this->resource->identityVisibleTo($request->user());

        return [
            'id' => $this->id,
            'reference_no' => $this->reference_no,
            'category' => $this->category,
            'description' => $this->description,
            'reported_person' => $this->reported_person,
            // Supporting documents. Filenames are already generalised for anonymous
            // concerns at storage time; the path is a random hash, never the original.
            'attachments' => collect($this->attachments ?? [])
                ->map(fn (array $a) => [
                    'name' => $a['name'] ?? 'Attachment',
                    'url' => PrivateFileService::url($a['path']),
                    'size' => $a['size'] ?? null,
                    'mime' => $a['mime'] ?? null,
                ])
                ->values(),
            'is_anonymous' => $this->is_anonymous,
            // When the reporter agreed to be named, or null while they have not been asked or
            // have said no. Sent to both sides: the reporter is entitled to know that HR can
            // now see them, and to have that stated rather than inferred.
            'identity_revealed_at' => $this->identity_revealed_at,
            'is_safety' => $this->category === 'safety',
            'status' => $this->status,
            'submitted_at' => $this->submitted_at,
            'resolved_at' => $this->resolved_at,
            // Withdrawn by the requester — kept apart from the review fields so a cancellation
            // is never read back as HR having declined it.
            'cancelled_at' => $this->cancelled_at,
            'cancelled_by' => $revealIdentity ? $this->canceller?->name : null,
            'cancel_reason' => $this->cancel_reason,
            // Named concerns: always. Anonymous: HR only (must keep it private).
            'employee' => $revealIdentity ? [
                'id' => $this->employee_id,
                'name' => $this->whenLoaded('employee', fn () => $this->employee?->user?->name),
            ] : null,
            // The live negotiation over the reporter's identity — the newest ask and its answer.
            // Both sides see the same object: the reporter needs it to decide, and HR needs to
            // see a refusal and its reason instead of a silence they have to interpret.
            'reveal_request' => $this->resource->relationLoaded('revealRequests')
                && ($latest = $this->revealRequests->first()) !== null
                    ? ConcernRevealRequestResource::make($latest)
                    : null,
            'meetings' => MeetingResource::collection($this->whenLoaded('meetings')),
            'created_at' => $this->created_at,
        ];
    }
}
