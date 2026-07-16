<?php

declare(strict_types=1);

namespace App\Modules\Projects\Events;

class StatusDeleted extends ProjectBroadcastEvent
{
    public function __construct(string $statusUuid, string $projectUuid)
    {
        parent::__construct($projectUuid, [
            'id' => $statusUuid,
            'project_id' => $projectUuid,
        ]);
    }

    public function broadcastAs(): string
    {
        return 'StatusDeleted';
    }
}
