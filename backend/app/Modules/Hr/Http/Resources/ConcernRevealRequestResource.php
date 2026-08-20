<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One ask, and its answer. */
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
