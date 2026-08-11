<?php

declare(strict_types=1);

namespace App\Modules\Projects\Resources;

use App\Modules\Projects\Models\ActivityLog;
use App\Modules\Projects\Services\ActivityFeedService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the Recent Activity feed.
 *
 * Richer than {@see ActivityLogResource}, which serves a single task's History
 * panel where the task is already on screen: a feed row has to name the work it
 * refers to, and resolve a status change into the status's name (the log stores
 * raw ids — {@see ActivityFeedService}).
 *
 * @mixin ActivityLog
 */
class ActivityFeedResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'action' => $this->action,
            'user' => new UserResource($this->whenLoaded('user')),
            // The parent task is always present, and is what a feed row opens.
            'task' => $this->whenLoaded('task', fn () => [
                'id' => $this->task->uuid,
                'title' => $this->task->title,
            ]),
            // Set on sub-task events; the row still opens the parent task.
            'subtask' => $this->whenLoaded(
                'subtask',
                fn () => $this->subtask === null ? null : [
                    'id' => $this->subtask->uuid,
                    'name' => $this->subtask->name,
                ],
                null,
            ),
            'project' => $this->whenLoaded(
                'task',
                fn () => $this->task->project === null ? null : [
                    'id' => $this->task->project->uuid,
                    'name' => $this->task->project->name,
                ],
                null,
            ),
            'old_status' => $this->old_status_name,
            'new_status' => $this->new_status_name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
