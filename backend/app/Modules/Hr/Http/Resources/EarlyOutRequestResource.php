<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EarlyOutRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_date' => $this->work_date?->format('Y-m-d'),
            // Normalise to HH:MM for the client regardless of how MySQL returns it.
            'early_out_time' => $this->early_out_time
                ? substr((string) $this->early_out_time, 0, 5)
                : null,
            'reason' => $this->reason,
            'status' => $this->status,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at,
            'cancelled_at' => $this->cancelled_at,
            'cancelled_by' => $this->canceller?->name,
            'cancel_reason' => $this->cancel_reason,
            'reviewed_by' => $this->reviewer?->name,
            'employee' => [
                'id' => $this->employee_id,
                'name' => $this->whenLoaded('employee', fn () => $this->employee?->user?->name),
                'photo_url' => $this->whenLoaded('employee', fn () => $this->employee?->photo_url),
            ],
            'created_at' => $this->created_at,
        ];
    }
}
