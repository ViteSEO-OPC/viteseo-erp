<?php

declare(strict_types=1);

namespace App\Modules\Projects\Resources;

use App\Modules\Projects\Resources\UserResource;
use App\Modules\Projects\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ActivityLog
 */
class ActivityLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'task_id' => $this->whenLoaded('task', fn () => $this->task->uuid),
            'subtask_id' => $this->whenLoaded('subtask', fn () => $this->subtask->uuid),
            'action' => $this->action,
            'field_changed' => $this->field_changed,
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
