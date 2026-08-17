<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OvertimeRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_date' => $this->work_date?->format('Y-m-d'),
            // Normalise to HH:MM regardless of how MySQL returns a TIME column.
            'time_from' => $this->time_from ? substr((string) $this->time_from, 0, 5) : null,
            'time_to' => $this->time_to ? substr((string) $this->time_to, 0, 5) : null,
            'hours' => (float) $this->hours,
            'reason' => $this->reason,
            'status' => $this->status,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at,
            'reviewed_by' => $this->reviewer?->name,
            // Slice 6 — HR cancelled an approved request before it was paid.
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
