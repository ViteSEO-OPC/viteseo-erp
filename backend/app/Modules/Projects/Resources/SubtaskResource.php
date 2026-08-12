<?php

declare(strict_types=1);

namespace App\Modules\Projects\Resources;

use App\Modules\Projects\Resources\UserResource;
use App\Modules\Projects\Models\Subtask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subtask
 */
class SubtaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'task_id' => $this->whenLoaded('task', fn () => $this->task->uuid),
            'name' => $this->name,
            'description' => $this->description,
            'assignee_id' => $this->assignee_id,
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            'status_id' => $this->whenLoaded('status', fn () => $this->status?->uuid),
            'status' => $this->whenLoaded('status', fn () => $this->status ? [
                'id' => $this->status->uuid,
                'name' => $this->status->name,
                'key' => $this->status->key?->value,
                'color' => $this->status->color,
            ] : null),
            'priority' => $this->priority?->value,
            'effort_score' => $this->effort_score,
            'deadline' => $this->deadline?->toIso8601String(),
            'flag_reason' => $this->flag_reason?->value,
            'flagged_at' => $this->flagged_at?->toIso8601String(),
            // Review state — 'approved' for everything a manager creates directly.
            'approval_status' => $this->approval_status?->value,
            'rejection_reason' => $this->rejection_reason,
            'submitted_by_id' => $this->submitted_by_id,
            'submitted_by' => new UserResource($this->whenLoaded('submittedBy')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
