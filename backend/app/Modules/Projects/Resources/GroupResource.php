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
            'project_id' => $this->project?->uuid,
            'name' => $this->name,
            'color' => $this->color,
            'archived' => (bool) $this->archived,
            'leader' => new UserResource($this->whenLoaded('leader')),
            'members_count' => $this->whenCounted('members'),
            'members' => UserResource::collection($this->whenLoaded('members')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
