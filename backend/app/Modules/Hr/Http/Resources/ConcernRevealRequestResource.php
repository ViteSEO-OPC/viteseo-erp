<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ask, and its answer.
 *
 * Emitted to BOTH sides, unredacted, on purpose: the reporter must see who is asking and why
 * before deciding, and HR must see the refusal and its reason rather than a silence. Nothing
 * here carries the reporter's identity — that lives on the concern, behind
 * `identity_revealed_at`, and is not restated in this shape.
 */
class ConcernRevealRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'reason' => $this->reason,
            // Named to the reporter deliberately: being asked to give up anonymity by someone
            // who stays anonymous themselves is the imbalance this flow exists to remove.
            'requested_by' => $this->requester?->name,
            'requested_at' => $this->created_at,
            'responded_at' => $this->responded_at,
            'decline_reason' => $this->decline_reason,
        ];
    }
}
