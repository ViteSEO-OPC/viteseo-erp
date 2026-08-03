<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\PerformanceTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PerformanceTarget */
class PerformanceTargetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'userId' => (string) $this->user_id,
            'userName' => $this->whenLoaded('user', fn (): ?string => $this->user?->name),
            'periodType' => $this->period_type->value,
            'periodStart' => $this->period_start?->toDateString(),
            'metric' => $this->metric->value,
            'metricLabel' => $this->metric->label(),
            'targetValue' => $this->target_value,
            'isActive' => $this->is_active,
        ];
    }
}
