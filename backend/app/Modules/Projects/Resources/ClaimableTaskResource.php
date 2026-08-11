<?php

declare(strict_types=1);

namespace App\Modules\Projects\Resources;

use App\Modules\Projects\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the open-task pool.
 *
 * Carries the project's NAME, unlike {@see TaskSummaryResource} which only
 * carries its id: a board row already sits inside its project, but a pool row
 * does not — the pool spans projects the viewer may not be a member of, so the
 * client has no local list to resolve the id against.
 *
 * @mixin Task
 */
class ClaimableTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'id' => $this->project->uuid,
                'name' => $this->project->name,
            ], null),
            'status' => $this->whenLoaded('status', fn () => $this->status === null ? null : [
                'id' => $this->status->uuid,
                'name' => $this->status->name,
                'key' => $this->status->key?->value,
                'color' => $this->status->color,
            ], null),
            'priority' => $this->priority?->value,
            'effort_score' => $this->effort_score,
            'deadline' => $this->deadline?->toIso8601String(),
            // Who put it up for grabs — context for deciding whether to take it.
            'posted_by' => new UserResource($this->whenLoaded('submittedBy')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
