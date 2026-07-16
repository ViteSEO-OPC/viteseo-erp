<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entitled = (float) $this->entitled_days;
        $used = (float) $this->used_days;

        return [
            'id' => $this->id,
            'year' => $this->year,
            'entitled_days' => $entitled,
            'used_days' => $used,
            'remaining_days' => round($entitled - $used, 1),
            'leave_type' => [
                'id' => $this->leaveType->id,
                'name' => $this->leaveType->name,
                'code' => $this->leaveType->code,
                'color' => $this->leaveType->color,
            ],
        ];
    }
}
