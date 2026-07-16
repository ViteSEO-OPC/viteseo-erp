<?php

declare(strict_types=1);

namespace App\Modules\Projects\Events;

use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Resources\TaskResource;

class TaskUpdated extends ProjectBroadcastEvent
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
        return 'TaskUpdated';
    }
}
