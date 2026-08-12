<?php

declare(strict_types=1);

namespace App\Modules\Projects\Events;

use App\Modules\Projects\Models\Comment;
use App\Modules\Projects\Resources\CommentResource;

/**
 * An author revised their comment — everyone reading the thread should see the
 * new text (and the "edited" marker) without reopening the task.
 */
class CommentUpdated extends ProjectBroadcastEvent
{
    /**
     * @param  string  $taskUuid  Parent task uuid (the task whose modal should refresh,
     *                            even for a subtask comment).
     */
    public function __construct(Comment $comment, string $projectUuid, string $taskUuid)
    {
        $comment->loadMissing('author', 'task', 'subtask');

        $payload = (new CommentResource($comment))->resolve(request());
        $payload['project_id'] = $projectUuid;
        $payload['task_id'] = $taskUuid;

        parent::__construct($projectUuid, $payload);
    }

    public function broadcastAs(): string
    {
        return 'CommentUpdated';
    }
}
