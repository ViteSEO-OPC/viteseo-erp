<?php

declare(strict_types=1);

namespace App\Modules\Projects\Resources;

use App\Modules\Projects\Resources\UserResource;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full task payload returned when a single task is opened by its UUID.
 *
 * Includes the description plus every nested relation (subtasks, comments,
 * links, activity logs). Populated by {@see TaskService::detail()}.
 *
 * @mixin Task
 */
class TaskDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'project_id' => $this->project?->uuid,
            'project' => $this->whenLoaded('project', fn (): array => [
                'id' => $this->project->uuid,
                'name' => $this->project->name,
            ]),
            'title' => $this->title,
            'description' => $this->description,
            'assignment_type' => $this->assignment_type?->value,
            'group_id' => $this->group?->uuid,
            'group' => $this->whenLoaded('group', fn (): array => [
                'id' => $this->group->uuid,
                'name' => $this->group->name,
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
            'subtasks' => SubtaskResource::collection($this->whenLoaded('subtasks')),
            'comments' => CommentResource::collection($this->whenLoaded('comments')),
            'links' => LinkResource::collection($this->whenLoaded('links')),
            'activity_logs' => ActivityLogResource::collection($this->whenLoaded('activityLogs')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
