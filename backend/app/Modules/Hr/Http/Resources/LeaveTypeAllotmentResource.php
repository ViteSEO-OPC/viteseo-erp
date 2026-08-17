<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveTypeAllotmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'leave_type_id' => $this->leave_type_id,
            'employment_status' => $this->employment_status,
            'days_per_year' => (float) $this->days_per_year,
            'leave_type' => $this->whenLoaded('leaveType', fn () => [
                'id' => $this->leaveType?->id,
                'name' => $this->leaveType?->name,
                'code' => $this->leaveType?->code,
                'color' => $this->leaveType?->color,
            ]),
        ];
    }
}
