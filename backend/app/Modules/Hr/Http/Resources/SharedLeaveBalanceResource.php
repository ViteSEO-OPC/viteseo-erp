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
        $entitled = (int) $this->entitled_days;
        $used = (int) $this->used_days;

        return [
            'id' => $this->id,
            'year' => $this->year,
            'entitled_days' => $entitled,
            'used_days' => $used,
            'remaining_days' => $entitled - $used,
        ];
    }
}
