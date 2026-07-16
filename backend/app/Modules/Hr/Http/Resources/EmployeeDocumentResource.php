<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class EmployeeDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'size_bytes' => $this->size_bytes,
            'label' => $this->label,
            'url' => $this->file_path ? Storage::disk('public')->url($this->file_path) : null,
            'created_at' => $this->created_at?->format('Y-m-d'),
        ];
    }
}
