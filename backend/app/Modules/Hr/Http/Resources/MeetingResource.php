<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A meeting never emits submitter identity — identity (for named concerns) comes
 * from the concern context, so an anonymous concern's meeting can't leak who it's
 * with even by accident.
 */
class MeetingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scheduled_at' => $this->scheduled_at,
            'location' => $this->location,
            'agenda' => $this->agenda,
            'status' => $this->status,
            'invite_response_at' => $this->invite_response_at,
            'concern' => $this->concern ? [
                'id' => $this->concern->id,
                'reference_no' => $this->concern->reference_no,
                'category' => $this->concern->category,
                'is_anonymous' => $this->concern->is_anonymous,
            ] : null,
        ];
    }
}
