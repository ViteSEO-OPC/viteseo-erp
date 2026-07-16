<?php

declare(strict_types=1);

namespace App\Modules\Projects\Events;

class CommentDeleted extends ProjectBroadcastEvent
{
    public function __construct(
        string $commentUuid,
        string $projectUuid,
        string $taskUuid,
        ?string $subtaskUuid = null,
    ) {
        parent::__construct($projectUuid, [
            'id' => $commentUuid,
            'project_id' => $projectUuid,
            'task_id' => $taskUuid,
            'subtask_id' => $subtaskUuid,
        ]);
    }

    public function broadcastAs(): string
    {
        return 'CommentDeleted';
    }
}
