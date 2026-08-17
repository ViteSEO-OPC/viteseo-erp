<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestDayRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_date' => $this->work_date?->format('Y-m-d'),
            // The time an approval will stamp. Named for what it MEANS on the wire, because
            // "requested_at" reads as metadata and this is the payroll-relevant figure.
            'requested_at' => $this->requested_at,
            'reason' => $this->reason,
            'status' => $this->status,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at,
            'reviewed_by' => $this->reviewer?->name,
            'cancelled_at' => $this->cancelled_at,
            'cancelled_by' => $this->canceller?->name,
            'cancel_reason' => $this->cancel_reason,
            'employee' => [
                'id' => $this->employee_id,
                'name' => $this->whenLoaded('employee', fn () => $this->employee?->user?->name),
                'photo_url' => $this->whenLoaded('employee', fn () => $this->employee?->photo_url),
            ],
            'created_at' => $this->created_at,
        ];
    }
}
