<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Events\NotificationCreated;
use App\Models\User;
use App\Modules\Projects\Enums\AssignmentType;
use App\Modules\Projects\Enums\UserRole;
use App\Modules\Projects\Models\Comment;
use App\Modules\Projects\Models\Notification;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Resources\NotificationResource;
use App\Support\SafeBroadcast;
use Illuminate\Support\Facades\DB;

/**
 * Creates in-app notifications and broadcasts them on the recipient's private
 * user channel. Scope: task assignment + task comments (to the assignee /
 * commenters) and task status changes (to the project's managers).
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
     * A comment was posted on a task — notify the task's individual assignee,
     * everyone who previously commented, and the project's managers (PM tier),
     * excluding the comment's author.
     */
    public function notifyTaskComment(Comment $comment, Task $task, User $author): void
    {
        $recipientIds = collect();

        if ($task->assignment_type === AssignmentType::INDIVIDUAL && $task->assignee_id) {
            $recipientIds->push((int) $task->assignee_id);
        }

        // Project managers hear about every comment so they can track discussion
        // on tasks they don't own (mirrors notifyTaskStatusChanged's recipients).
        $managerIds = $task->project
            ->members()
            ->role(UserRole::managerRoles())
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id);

        $recipientIds = $recipientIds
            ->merge($task->comments()->pluck('author_id')->map(fn ($id): int => (int) $id))
            ->merge($managerIds)
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
     * A task's status changed — notify the project's managers (PM tier) so they
     * can track progress, excluding whoever made the change (a manager moving a
     * card doesn't notify themselves).
     */
    public function notifyTaskStatusChanged(Task $task, User $actor, string $newStatusName): void
    {
        $recipientIds = $task->project
            ->members()
            ->role(UserRole::managerRoles())
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->reject(fn (int $id): bool => $id === (int) $actor->id)
            ->values();

        foreach ($recipientIds as $recipientId) {
            $notification = Notification::create([
                'user_id' => $recipientId,
                'actor_id' => $actor->id,
                'type' => 'task.status_changed',
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'data' => [
                    'title' => $task->title,
                    'status' => $newStatusName,
                ],
            ]);

            $this->dispatch($notification);
        }
    }

    /**
     * Broadcast only after the surrounding DB transaction commits, so a
     * rolled-back notification row never reaches a client. (Runs immediately
     * when there is no open transaction.)
     *
     * The event is the platform's, and the payload is built HERE from this
     * module's own Resource — the shared `user.{id}` stream carries every
     * module's notifications, tagged with `module` so each client listener keeps
     * only its own. Relations are eager-loaded before resolving so the payload is
     * complete without a re-fetch at broadcast time.
     */
    private function dispatch(Notification $notification): void
    {
        $notification->loadMissing('actor', 'task', 'project');
        $payload = (new NotificationResource($notification))->resolve(request());

        DB::afterCommit(fn () => SafeBroadcast::dispatch(
            new NotificationCreated((int) $notification->user_id, 'projects', $payload)
        ));
    }
}
