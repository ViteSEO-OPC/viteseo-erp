<?php

declare(strict_types=1);

namespace App\Modules\Projects\Resources;

use App\Modules\Projects\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member's task proposal as it appears in the PM/Admin review queue.
 *
 * `id` is the task's own UUID, so a row opens the ordinary task modal (?task=)
 * where the reviewer edits it before deciding.
 *
 * @mixin Task
 */
class PendingTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'project_id' => $this->project?->uuid,
            'project_name' => $this->project?->name,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority?->value,
            'effort_score' => $this->effort_score,
            'status' => $this->whenLoaded('status', fn () => $this->status ? [
                'id' => $this->status->uuid,
                'name' => $this->status->name,
                'key' => $this->status->key?->value,
                'color' => $this->status->color,
            ] : null),
            'deadline' => $this->deadline?->toIso8601String(),
            'start_date' => $this->start_date?->toIso8601String(),
            'subtasks_count' => $this->whenCounted('subtasks'),
            'approval_status' => $this->approval_status?->value,
            'rejection_reason' => $this->rejection_reason,
            'submitted_by' => new UserResource($this->whenLoaded('submittedBy')),
            'reviewed_by' => new UserResource($this->whenLoaded('reviewedBy')),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
