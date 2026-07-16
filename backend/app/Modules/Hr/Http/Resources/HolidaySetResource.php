<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HolidaySetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'country_code' => $this->country_code,
            'year' => $this->year,
            'source' => $this->source,
            'refreshed_at' => $this->refreshed_at,
            'holidays_count' => $this->holidays_count,
            'holidays' => HolidayResource::collection($this->whenLoaded('holidays')),
            'created_at' => $this->created_at,
        ];
    }
}
