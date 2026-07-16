<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Models\User;
use App\Modules\Projects\Models\Comment;
use App\Modules\Projects\Models\Subtask;
use App\Modules\Projects\Models\Task;

class CommentService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Create a comment on a task. Enforces polymorphism (R2): task_id set,
     * subtask_id null, by construction.
     *
     * @param  array<string, mixed>  $data
     */
    public function createForTask(Task $task, array $data, User $user): Comment
    {
        $comment = Comment::create([
            'task_id' => $task->id,
            'subtask_id' => null,
            'author_id' => $user->id,
            'content' => $data['content'],
        ]);

        $this->notifications->notifyTaskComment($comment, $task, $user);

        return $comment;
    }

    /**
     * Create a comment on a subtask. Enforces polymorphism (R2): subtask_id
     * set, task_id null, by construction.
     *
     * @param  array<string, mixed>  $data
     */
    public function createForSubtask(Subtask $subtask, array $data, User $user): Comment
    {
        return Comment::create([
            'task_id' => null,
            'subtask_id' => $subtask->id,
            'author_id' => $user->id,
            'content' => $data['content'],
        ]);
    }

    public function delete(Comment $comment): void
    {
        $comment->delete();
    }
}
