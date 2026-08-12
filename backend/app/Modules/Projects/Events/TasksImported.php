<?php

declare(strict_types=1);

namespace App\Modules\Projects\Events;

use App\Modules\Projects\Models\Project;

/**
 * A bulk import landed on this project's board.
 *
 * One event for the whole batch, deliberately — TaskCreated is ShouldBroadcastNow
 * and publishes synchronously inside the request, so looping it would mean one
 * blocking round-trip to Reverb per imported task. Watchers only invalidate on
 * TaskCreated anyway, so a single "go refetch" carries the same information at a
 * fraction of the cost.
 */
class TasksImported extends ProjectBroadcastEvent
{
    public function __construct(Project $project, int $taskCount, int $subtaskCount)
    {
        parent::__construct((string) $project->uuid, [
            'project_id' => (string) $project->uuid,
            'task_count' => $taskCount,
            'subtask_count' => $subtaskCount,
        ]);
    }

    public function broadcastAs(): string
    {
        return 'TasksImported';
    }
}
