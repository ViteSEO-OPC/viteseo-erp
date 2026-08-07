<?php

declare(strict_types=1);

namespace App\Modules\Projects\Resources;

use App\Modules\Projects\Resources\UserResource;
use App\Modules\Projects\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Comment
 */
class CommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'task_id' => $this->whenLoaded('task', fn () => $this->task->uuid),
            'subtask_id' => $this->whenLoaded('subtask', fn () => $this->subtask->uuid),
            'content' => $this->content,
            // The version the thread first saw. Null until the first edit, which
            // is also how the client knows whether to offer "View Original".
            'original_content' => $this->original_content,
            'edited_at' => $this->edited_at?->toIso8601String(),
            'author_id' => $this->author_id,
            'author' => new UserResource($this->whenLoaded('author')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
