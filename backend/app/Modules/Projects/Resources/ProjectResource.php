<?php

declare(strict_types=1);

namespace App\Modules\Projects\Resources;

use App\Modules\Projects\Resources\UserResource;
use App\Modules\Projects\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'workspace_id' => $this->workspace?->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'priority' => $this->priority,
            'color' => $this->color,
            'status' => $this->status?->value,
            'workspace' => new WorkspaceResource($this->whenLoaded('workspace')),
            'statuses' => ProjectStatusResource::collection($this->whenLoaded('statuses')),
            'members' => UserResource::collection($this->whenLoaded('members')),
            'members_count' => $this->whenCounted('members'),
            'tasks_count' => $this->whenCounted('tasks'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
