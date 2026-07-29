<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class DocumentRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'other_detail' => $this->other_detail,
            'reason' => $this->reason,
            'status' => $this->status,
            'fulfilled_url' => $this->fulfilled_path ? Storage::disk('public')->url($this->fulfilled_path) : null,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at,
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->name),
            'employee' => [
                'id' => $this->employee_id,
                'name' => $this->whenLoaded('employee', fn () => $this->employee->user?->name),
                'photo_url' => $this->whenLoaded('employee', fn () => $this->employee->photo_url),
            ],
            'created_at' => $this->created_at,
        ];
    }
}
