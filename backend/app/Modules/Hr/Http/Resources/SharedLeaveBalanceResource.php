<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SharedLeaveBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Whole days only — leave can't be a half-day against the pool.
        // FLOAT, not int. These are decimal(5,1) columns and half-day leave stores real
        // halves, so an int cast reported 2.5 days used as 2 and gifted back half a spent day.
        $entitled = (float) $this->entitled_days;
        $used = (float) $this->used_days;

        return [
            'id' => $this->id,
            'year' => $this->year,
            'entitled_days' => $entitled,
            'used_days' => $used,
            'remaining_days' => round($entitled - $used, 1),
        ];
    }
}
