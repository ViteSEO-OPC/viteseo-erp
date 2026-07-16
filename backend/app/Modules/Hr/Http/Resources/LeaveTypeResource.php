<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'days_per_year' => $this->days_per_year,
            'is_paid' => (bool) $this->is_paid,
            'color' => $this->color,
            'allow_backdated' => (bool) $this->allow_backdated,
            'backdate_limit_days' => $this->backdate_limit_days,
        ];
    }
}
