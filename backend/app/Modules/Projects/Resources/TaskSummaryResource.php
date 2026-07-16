<?php

declare(strict_types=1);

namespace App\Modules\Projects\Resources;

use App\Modules\Projects\Resources\UserResource;
use App\Modules\Projects\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight task payload for the board views (list, kanban, calendar).
 *
 * Intentionally omits the heavy relations (description, subtasks, comments,
 * links, activity logs) — those are only fetched when a single task is opened
 * via {@see TaskDetailResource}.
 *
 * @mixin Task
 */
class TaskSummaryResource extends JsonResource
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
            'assignment_type' => $this->assignment_type?->value,
            'group_id' => $this->group?->uuid,
            'group' => $this->whenLoaded('group', fn (): array => [
                'id' => $this->group->uuid,
                'name' => $this->group->name,
            ]),
            'assignee_id' => $this->assignee_id,
            'assignee' => new UserResource($this->whenLoaded('assignee')),
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
            // True when the requesting user has a subtask assigned under this task
            // (so it can surface in their "My Tasks" board even without the parent
            // being assigned directly to them).
            'has_my_subtask' => (bool) ($this->my_subtasks_count ?? 0),
            // The requesting user's own subtasks under this task (loaded only for
            // the board list) — the member board renders these as their own cards.
            'my_subtasks' => $this->whenLoaded('subtasks', fn () => $this->subtasks->map(fn ($s): array => [
                'id' => $s->uuid,
                'name' => $s->name,
                'status_id' => $s->status?->uuid,
                'status' => $s->status ? [
                    'id' => $s->status->uuid,
                    'name' => $s->status->name,
                    'key' => $s->status->key?->value,
                    'color' => $s->status->color,
                ] : null,
                'priority' => $s->priority?->value,
                'deadline' => $s->deadline?->toIso8601String(),
                'effort_score' => $s->effort_score,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
