<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HolidayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'date' => $this->date?->format('Y-m-d'),
            'type' => $this->type,
            'source' => $this->source,
            'needs_review' => $this->needs_review,
            'is_weekend' => $this->date ? $this->date->isWeekend() : false,
        ];
    }
}
