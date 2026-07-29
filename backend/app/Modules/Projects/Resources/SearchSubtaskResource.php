<?php

declare(strict_types=1);

namespace App\Modules\Projects\Resources;

use App\Modules\Projects\Models\Subtask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A sub-task as it appears in the topbar's global-search menu.
 *
 * Sub-tasks have no view of their own — they live inside their parent task's
 * modal — so the parent's uuid ships here as the row's open target, and the
 * project comes along as context.
 *
 * @mixin Subtask
 */
class SearchSubtaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'priority' => $this->priority?->value,
            'deadline' => $this->deadline?->toIso8601String(),
            'task' => $this->whenLoaded('task', fn () => $this->task ? [
                'id' => $this->task->uuid,
                'title' => $this->task->title,
            ] : null),
            'project' => $this->whenLoaded('task', fn () => $this->task?->project ? [
                'id' => $this->task->project->uuid,
                'name' => $this->task->project->name,
            ] : null),
            'status' => $this->whenLoaded('status', fn () => $this->status ? [
                'id' => $this->status->uuid,
                'name' => $this->status->name,
                'key' => $this->status->key?->value,
                'color' => $this->status->color,
            ] : null),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
                'avatar' => $this->assignee->avatar,
            ] : null),
        ];
    }
}
