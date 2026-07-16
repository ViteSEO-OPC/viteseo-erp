<?php

declare(strict_types=1);

namespace App\Modules\Projects\Events;

use App\Modules\Projects\Models\Subtask;
use App\Modules\Projects\Resources\SubtaskResource;

class SubtaskUpdated extends ProjectBroadcastEvent
{
    public function __construct(Subtask $subtask, string $projectUuid, string $taskUuid)
    {
        $subtask->loadMissing('task', 'status', 'assignee');

        $payload = (new SubtaskResource($subtask))->resolve(request());
        $payload['project_id'] = $projectUuid;
        $payload['task_id'] = $taskUuid;

        parent::__construct($projectUuid, $payload);
    }

    public function broadcastAs(): string
    {
        return 'SubtaskUpdated';
    }
}
