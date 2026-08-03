<?php

declare(strict_types=1);

namespace App\Modules\Projects\Resources;

use App\Modules\Projects\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A project as it appears in the topbar's global-search menu. Deliberately
 * separate from {@see ProjectResource}: a result row needs a label, a bit of
 * context and a link target — not the project's full payload.
 *
 * @mixin Project
 */
class SearchProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            // Truncated — the menu row shows one line of context, not a body.
            'description' => $this->description !== null
                ? Str::limit($this->description, 120)
                : null,
            'color' => $this->color,
            'status' => $this->status?->value,
            'workspace' => $this->whenLoaded('workspace', fn () => $this->workspace ? [
                'id' => $this->workspace->uuid,
                'name' => $this->workspace->name,
            ] : null),
            'tasks_count' => $this->whenCounted('tasks'),
        ];
    }
}
