<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Hr\Models\RetroFlag */
class RetroFlagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => [
                'id' => $this->employee?->id,
                'name' => $this->employee?->user?->name ?? $this->employee?->name,
            ],
            // owed = settle with an adjustment run; overpaid = needs consent to recover.
            'direction' => $this->direction,
            'reason' => $this->reason,
            'period' => $this->payrollRun
                ? $this->payrollRun->cycle_start->format('Y-m-d') . ' – ' . $this->payrollRun->cycle_end->format('Y-m-d')
                : null,
            'source' => class_basename($this->flaggable_type),
            'source_id' => $this->flaggable_id,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_note' => $this->resolution_note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
