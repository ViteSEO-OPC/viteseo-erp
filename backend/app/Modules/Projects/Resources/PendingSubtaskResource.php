<?php

declare(strict_types=1);

namespace App\Modules\Projects\Resources;

use App\Modules\Projects\Models\Subtask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A stand-alone sub-task proposal in the PM/Admin review queue.
 *
 * `task_id` is the parent's UUID so a row opens that task's modal (?task=),
 * where the reviewer edits the sub-task before deciding.
 *
 * @mixin Subtask
 */
class PendingSubtaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'task_id' => $this->task?->uuid,
            'task_name' => $this->task?->title,
            'project_id' => $this->task?->project?->uuid,
            'project_name' => $this->task?->project?->name,
            'name' => $this->name,
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
