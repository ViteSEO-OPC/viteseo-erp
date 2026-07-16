<?php

declare(strict_types=1);

namespace App\Modules\Projects\Events;

use App\Modules\Projects\Models\ProjectStatus;
use App\Modules\Projects\Resources\ProjectStatusResource;

class StatusUpdated extends ProjectBroadcastEvent
{
    public function __construct(ProjectStatus $status)
    {
        $status->loadMissing('project');

        parent::__construct(
            (string) $status->project->uuid,
            (new ProjectStatusResource($status))->resolve(request()),
        );
    }

    public function broadcastAs(): string
    {
        return 'StatusUpdated';
    }
}
