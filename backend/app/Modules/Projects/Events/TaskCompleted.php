<?php

declare(strict_types=1);

namespace App\Modules\Projects\Events;

use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Resources\TaskResource;

/**
 * Broadcast when a task transitions into the 'done' status, so every viewer of
 * the project celebrates with confetti. Purely a UI signal — the actual data
 * change is already carried by TaskUpdated.
 */
class TaskCompleted extends ProjectBroadcastEvent
{
    public function __construct(Task $task)
    {
        $task->loadMissing('project', 'status', 'group', 'assignee', 'assignedBy');

        parent::__construct(
            (string) $task->project->uuid,
            (new TaskResource($task))->resolve(request()),
        );
    }

    public function broadcastAs(): string
    {
        return 'TaskCompleted';
    }
}
