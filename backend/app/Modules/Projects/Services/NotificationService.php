<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Events\NotificationCreated;
use App\Models\User;
use App\Modules\Projects\Enums\AssignmentType;
use App\Modules\Projects\Enums\UserRole;
use App\Modules\Projects\Models\Comment;
use App\Modules\Projects\Models\Notification;
use App\Modules\Projects\Models\Subtask;
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
     * Someone took an open task off the pool.
     *
     * Goes to whoever posted it (they asked for a taker and now have one) plus
     * the project's managers, who need to know a person from another pod is now
     * carrying work on their client. The claimer is never notified of their own
     * claim — reviewersOf() already drops the actor, and the poster check does
     * the same for a member claiming back their own posting.
     */
    public function notifyTaskClaimed(Task $task, User $claimer): void
    {
        $recipientIds = collect($this->reviewersOf($task, $claimer));

        if ($task->submitted_by_id !== null) {
            $recipientIds->push((int) $task->submitted_by_id);
        }

        $recipientIds = $recipientIds
            ->unique()
            ->reject(fn (int $id): bool => $id === (int) $claimer->id)
            ->values();

        foreach ($recipientIds as $recipientId) {
            $notification = Notification::create([
                'user_id' => $recipientId,
                'actor_id' => $claimer->id,
                'type' => 'task.claimed',
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'data' => ['title' => $task->title],
            ]);

            $this->dispatch($notification);
        }
    }

    /**
     * A member submitted a task proposal — tell the reviewers it is queued.
     */
    public function notifyTaskReviewRequested(Task $task, User $actor): void
    {
        foreach ($this->reviewersOf($task, $actor) as $recipientId) {
            $this->dispatch(Notification::create([
                'user_id' => $recipientId,
                'actor_id' => $actor->id,
                'type' => 'task.review_requested',
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'data' => ['title' => $task->title],
            ]));
        }
    }

    /**
     * A member submitted a stand-alone sub-task proposal.
     */
    public function notifySubtaskReviewRequested(Subtask $subtask, User $actor): void
    {
        $subtask->loadMissing('task');
        $task = $subtask->task;

        foreach ($this->reviewersOf($task, $actor) as $recipientId) {
            $this->dispatch(Notification::create([
                'user_id' => $recipientId,
                'actor_id' => $actor->id,
                'type' => 'subtask.review_requested',
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'data' => ['title' => $subtask->name, 'task_title' => $task->title],
            ]));
        }
    }

    /**
     * A reviewer approved or rejected a proposal — tell its author. $type is one
     * of task.approved / task.rejected / subtask.approved / subtask.rejected.
     */
    public function notifyApprovalDecision(
        Task $task,
        int $recipientId,
        User $actor,
        string $type,
        string $title,
        ?string $reason = null,
    ): void {
        if ($recipientId === (int) $actor->id) {
            return;
        }

        $this->dispatch(Notification::create([
            'user_id' => $recipientId,
            'actor_id' => $actor->id,
            'type' => $type,
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'data' => array_filter([
                'title' => $title,
                'reason' => $reason,
            ], fn ($v): bool => $v !== null),
        ]));
    }

    /**
     * Who reviews this project's proposals: its manager-tier members, excluding
     * the actor. Falls back to every manager-tier user when the project has no
     * manager on its roster, so a proposal is never submitted into a void.
     *
     * @return list<int>
     */
    private function reviewersOf(Task $task, User $actor): array
    {
        $task->loadMissing('project');

        $recipientIds = $task->project
            ->members()
            ->role(UserRole::managerRoles())
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id);

        if ($recipientIds->isEmpty()) {
            // Fallback recipients: without the org fence this notified every tenant's
            // managers about one tenant's task.
            $recipientIds = User::query()
                ->inCurrentOrg()
                ->role(UserRole::managerRoles())
                ->pluck('id')
                ->map(fn ($id): int => (int) $id);
        }

        return $recipientIds
            ->unique()
            ->reject(fn (int $id): bool => $id === (int) $actor->id)
            ->values()
            ->all();
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
