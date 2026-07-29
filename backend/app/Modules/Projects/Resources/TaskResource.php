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
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'project_id' => $this->project?->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'assignment_type' => $this->assignment_type?->value,
            'group_id' => $this->group?->uuid,
            'group' => $this->whenLoaded('group', fn (): array => [
                'id' => $this->group->uuid,
                'name' => $this->group->name,
                'color' => $this->group->color,
            ]),
            'assignee_id' => $this->assignee_id,
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            'assigned_by_id' => $this->assigned_by_id,
            'assigned_by' => new UserResource($this->whenLoaded('assignedBy')),
            'status_id' => $this->status?->uuid,
            'status' => $this->whenLoaded('status', fn () => $this->status ? [
                'id' => $this->status->uuid,
                'name' => $this->status->name,
                'key' => $this->status->key?->value,
                'color' => $this->status->color,
            ] : null),
            'priority' => $this->priority?->value,
            'effort_score' => $this->effort_score,
            'deadline' => $this->deadline?->toIso8601String(),
            'start_date' => $this->start_date?->toIso8601String(),
            'subtasks_count' => $this->whenCounted('subtasks'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
