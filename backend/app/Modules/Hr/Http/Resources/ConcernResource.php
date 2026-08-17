<?php

namespace App\Modules\Hr\Http\Resources;

use App\Modules\Hr\Services\PrivateFileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Anonymity is enforced HERE, not in the UI.
 *
 * A named concern shows its reporter to HR, as it always has. An ANONYMOUS one shows nobody —
 * not even HR — until the reporter has been asked and has agreed (see ConcernRevealService).
 * Before that, `reference_no` is the only handle anyone has.
 *
 * THIS IS A DELIBERATE NARROWING of the old rule, which let HR see every anonymous reporter
 * "to deter jokes and spam". That made anonymity a promise the system did not keep, and it
 * failed exactly the person it was for: someone reporting the manager who signs their payslip.
 * Deterrence now comes from the ask being on the record, not from the identity being free.
 *
 * The gate lives on the model (Concern::identityVisibleTo) so every other reader answers the
 * question the same way. `is_anonymous` is always sent so the UI can say "keep private", and
 * `reveal_request` carries the current ask to both sides.
 */
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
            //
            // The canceller IS the reporter, so the name rides the same anonymity gate as
            // `employee` below. Emitting it unconditionally would hand back, in a different
            // field, exactly the identity this resource withholds two lines further down.
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
            //
            // Emitted only where it can mean something: a named concern has nothing to reveal,
            // and a non-HR viewer who is not the reporter never reaches this resource at all
            // (the list endpoints scope by role).
            // Always present, null when there is nothing to negotiate — a named concern, or an
            // anonymous one nobody has asked about. `when()` would OMIT the key instead, and a
            // client cannot tell an absent key from a settled question without guessing.
            //
            // The relation is ordered newest-first, so `first()` is the live ask. Null rather
            // than a Resource wrapped around nothing: Resource::make(null) serialises into a
            // shape no caller can read.
            'reveal_request' => $this->resource->relationLoaded('revealRequests')
                && ($latest = $this->revealRequests->first()) !== null
                    ? ConcernRevealRequestResource::make($latest)
                    : null,
            'meetings' => MeetingResource::collection($this->whenLoaded('meetings')),
            'created_at' => $this->created_at,
        ];
    }
}
