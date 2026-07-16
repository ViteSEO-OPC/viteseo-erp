<?php

declare(strict_types=1);

namespace App\Modules\Projects\Resources;

use App\Modules\Projects\Models\ProjectStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectStatus
 */
class ProjectStatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'project_id' => $this->whenLoaded('project', fn () => $this->project->uuid),
            'name' => $this->name,
            'key' => $this->key?->value,
            'color' => $this->color,
            'is_protected' => (bool) $this->is_protected,
            'order' => $this->order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
