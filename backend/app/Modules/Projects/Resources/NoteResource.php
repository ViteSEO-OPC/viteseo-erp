<?php

declare(strict_types=1);

namespace App\Modules\Projects\Resources;

use App\Modules\Projects\Models\Note;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Note
 */
class NoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'scope' => $this->scope?->value,
            // The public project uuid, or null on a user-scoped note. The
            // service attaches the already-resolved project, so reading the
            // relation here costs no extra query.
            'project_id' => $this->relationLoaded('project') ? $this->project?->uuid : null,
            'content' => $this->content,
            'version' => $this->version,
            'last_saved_at' => $this->last_saved_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
