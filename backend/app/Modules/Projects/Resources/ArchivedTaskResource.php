<?php

declare(strict_types=1);

namespace App\Modules\Projects\Resources;

use App\Modules\Projects\Resources\UserResource;
use App\Modules\Projects\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Task
 */
class ArchivedTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'project_id' => $this->project?->uuid,
            'project_name' => $this->project?->name,
            'title' => $this->title,
            'description' => $this->description,
            'assignment_type' => $this->assignment_type?->value,
            'group_name' => $this->group?->name,
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            'priority' => $this->priority?->value,
            'status' => $this->whenLoaded('status', fn () => $this->status ? [
                'id' => $this->status->uuid,
                'name' => $this->status->name,
                'key' => $this->status->key?->value,
                'color' => $this->status->color,
            ] : null),
            'deadline' => $this->deadline?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
