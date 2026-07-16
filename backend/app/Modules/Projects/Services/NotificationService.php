<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Models\User;
use App\Modules\Projects\Enums\AssignmentType;
use App\Modules\Projects\Events\NotificationCreated;
use App\Modules\Projects\Models\Comment;
use App\Modules\Projects\Models\Notification;
use App\Modules\Projects\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Creates in-app notifications and broadcasts them on the recipient's private
 * user channel. Scope is intentionally minimal: task assignment + task comments.
 */
class NotificationService
{
    /**
     * The task was (re)assigned to an individual — notify the new assignee
     * (never the actor who made the change).
     */
    public function notifyTaskAssigned(Task $task, int $recipientId, User $actor): void
    {
        if ($recipientId === (int) $actor->id) {
            return;
        }

        $notification = Notification::create([
            'user_id' => $recipientId,
            'actor_id' => $actor->id,
            'type' => 'task.assigned',
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'data' => ['title' => $task->title],
        ]);

        $this->dispatch($notification);
    }

    /**
     * A comment was posted on a task — notify the task's individual assignee and
     * everyone who previously commented, excluding the comment's author.
     */
    public function notifyTaskComment(Comment $comment, Task $task, User $author): void
    {
        $recipientIds = collect();

        if ($task->assignment_type === AssignmentType::INDIVIDUAL && $task->assignee_id) {
            $recipientIds->push((int) $task->assignee_id);
        }

        $recipientIds = $recipientIds
            ->merge($task->comments()->pluck('author_id')->map(fn ($id): int => (int) $id))
            ->unique()
            ->reject(fn (int $id): bool => $id === (int) $author->id)
            ->values();

        foreach ($recipientIds as $recipientId) {
            $notification = Notification::create([
                'user_id' => $recipientId,
                'actor_id' => $author->id,
                'type' => 'comment.created',
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'comment_id' => $comment->id,
                'data' => [
                    'title' => $task->title,
                    'snippet' => str($comment->content)->limit(80)->value(),
                ],
            ]);

            $this->dispatch($notification);
        }
    }

    /**
     * Broadcast only after the surrounding DB transaction commits, so a
     * rolled-back notification row never reaches a client. (Runs immediately
     * when there is no open transaction.)
     */
    private function dispatch(Notification $notification): void
    {
        DB::afterCommit(fn () => broadcast(new NotificationCreated($notification)));
    }
}
