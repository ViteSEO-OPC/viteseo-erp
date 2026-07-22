<?php

declare(strict_types=1);

namespace App\Modules\Projects\Resources;

use App\Modules\Projects\Resources\UserResource;
use App\Modules\Projects\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Group
 */
class GroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'color' => $this->color,
            'archived' => (bool) $this->archived,
            'leader' => new UserResource($this->whenLoaded('leader')),
            'members_count' => $this->whenCounted('members'),
            'members' => UserResource::collection($this->whenLoaded('members')),
            // A group can span many projects (attached when a project holds its
            // whole roster). Lightweight embed — enough for list/detail display.
            'projects' => $this->whenLoaded('projects', fn () => $this->projects->map(fn ($p) => [
                'id' => $p->uuid,
                'name' => $p->name,
                'priority' => $p->priority,
                'status' => $p->status?->value,
            ])->all()),
            'projects_count' => $this->whenCounted('projects'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
