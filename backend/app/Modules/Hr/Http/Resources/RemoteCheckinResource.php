<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin \App\Modules\Hr\Models\RemoteCheckin
 */
class RemoteCheckinResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => [
                'id' => $this->employee_id,
                'name' => $this->employee?->user?->name ?? $this->employee?->name,
                'department' => $this->employee?->department?->name,
            ],
            'photo_url' => Storage::disk('public')->url($this->photo_path),
            'lat' => $this->lat,
            'lng' => $this->lng,
            'has_location' => $this->hasLocation(),
            'status' => $this->status,
            // THE official time-in — named for what it means rather than `created_at`, so
            // nobody reading the payload mistakes it for a bookkeeping timestamp.
            'submitted_at' => $this->created_at,
            'reviewed_at' => $this->reviewed_at,
            'reviewed_by' => $this->reviewer?->name,
            'rejection_note' => $this->rejection_note,
        ];
    }
}
