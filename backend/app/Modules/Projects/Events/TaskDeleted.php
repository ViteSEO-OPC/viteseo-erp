<?php

declare(strict_types=1);

namespace App\Modules\Projects\Events;

class TaskDeleted extends ProjectBroadcastEvent
{
    public function __construct(string $taskUuid, string $projectUuid)
    {
        parent::__construct($projectUuid, [
            'id' => $taskUuid,
            'project_id' => $projectUuid,
        ]);
    }

    public function broadcastAs(): string
    {
        return 'TaskDeleted';
    }
}
