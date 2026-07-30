<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HalfDayRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_date' => $this->work_date?->format('Y-m-d'),
            'period' => $this->period,
            'reason' => $this->reason,
            'status' => $this->status,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at,
            'reviewed_by' => $this->reviewer?->name,
            'employee' => [
                'id' => $this->employee_id,
                'name' => $this->whenLoaded('employee', fn () => $this->employee->user?->name),
                'photo_url' => $this->whenLoaded('employee', fn () => $this->employee->photo_url),
            ],
            'created_at' => $this->created_at,
        ];
    }
}
