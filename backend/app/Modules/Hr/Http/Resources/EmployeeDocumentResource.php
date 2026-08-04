<?php

namespace App\Modules\Hr\Http\Resources;

use App\Modules\Hr\Services\PrivateFileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'url' => PrivateFileService::url($this->file_path),
            'created_at' => $this->created_at?->format('Y-m-d'),
        ];
    }
}
