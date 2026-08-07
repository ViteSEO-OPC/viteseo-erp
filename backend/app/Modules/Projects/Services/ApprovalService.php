<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Models\User;
use App\Modules\Projects\Enums\ApprovalStatus;
use App\Modules\Projects\Models\ActivityLog;
use App\Modules\Projects\Models\Subtask;
use App\Modules\Projects\Models\Task;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The PM/Admin review queue for member-submitted work.
 *
 * A member's task proposal and any sub-tasks they drafted with it form one
 * bundle: approving or rejecting the task carries its pending sub-tasks along.
 * A sub-task proposed on an already-approved task is stand-alone and is reviewed
 * on its own.
 */
class ApprovalService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @param  array<string, mixed>  $filters  state: pending|rejected|all, search
     * @return LengthAwarePaginator<Task>
     */
    public function tasks(array $filters, int $perPage): LengthAwarePaginator
    {
        return Task::query()
            ->tap(fn (Builder $q) => $this->applyState($q, $filters['state'] ?? null))
            ->with(['project', 'status', 'assignee', 'submittedBy', 'reviewedBy'])
            ->withCount('subtasks')
            ->when(
                ! empty($filters['search']),
                fn (Builder $q) => $q->where('title', 'like', '%'.$filters['search'].'%'),
            )
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Stand-alone sub-task proposals only — one bundled with a task proposal is
     * reviewed through its parent, so it must not also queue on its own.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Subtask>
     */
    public function subtasks(array $filters, int $perPage): LengthAwarePaginator
    {
        return Subtask::query()
            ->tap(fn (Builder $q) => $this->applyState($q, $filters['state'] ?? null))
            ->whereHas('task', fn (Builder $q) => $q->approved())
            ->with(['task.project', 'status', 'assignee', 'submittedBy', 'reviewedBy'])
            ->when(
                ! empty($filters['search']),
                fn (Builder $q) => $q->where('name', 'like', '%'.$filters['search'].'%'),
            )
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function approveTask(Task $task, User $reviewer): Task
    {
        $this->assertNotApproved($task->approval_status, 'task');

        return DB::transaction(function () use ($task, $reviewer): Task {
            $task->update([
                'approval_status' => ApprovalStatus::APPROVED,
                'reviewed_by_id' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
                // The reviewer is who actually put this work on the board.
                'assigned_by_id' => $reviewer->id,
            ]);

            $this->cascadeBundle($task, ApprovalStatus::APPROVED, $reviewer);

            ActivityLog::create([
                'task_id' => $task->id,
                'user_id' => $reviewer->id,
                'action' => 'task.approved',
            ]);

            if ($task->submitted_by_id !== null) {
                $this->notifications->notifyApprovalDecision(
                    $task,
                    (int) $task->submitted_by_id,
                    $reviewer,
                    'task.approved',
                    $task->title,
                );
            }

            return $task;
        });
    }

    /**
     * Approve a batch of task proposals selected in the review queue.
     *
     * One decision per row, all in a single transaction, so a mid-batch failure
     * doesn't leave half the selection approved. Rows that are already approved
     * (or that the caller can no longer see) are reported as skipped instead of
     * failing the batch — the queue a reviewer selected from may be a moment
     * stale, and that shouldn't cost them the other nine approvals.
     *
     * @param  list<string>  $uuids
     * @return array{approved: list<Task>, skipped: int}
     */
    public function approveTasks(array $uuids, User $reviewer): array
    {
        return DB::transaction(function () use ($uuids, $reviewer): array {
            $tasks = Task::query()
                ->whereIn('uuid', $uuids)
                ->where('approval_status', '!=', ApprovalStatus::APPROVED->value)
                ->get();

            $approved = [];
            foreach ($tasks as $task) {
                $approved[] = $this->approveTask($task, $reviewer);
            }

            return [
                'approved' => $approved,
                'skipped' => count($uuids) - count($approved),
            ];
        });
    }

    /**
     * @param  list<string>  $uuids
     * @return array{approved: list<Subtask>, skipped: int}
     */
    public function approveSubtasks(array $uuids, User $reviewer): array
    {
        return DB::transaction(function () use ($uuids, $reviewer): array {
            $subtasks = Subtask::query()
                ->whereIn('uuid', $uuids)
                ->where('approval_status', '!=', ApprovalStatus::APPROVED->value)
                ->get();

            $approved = [];
            foreach ($subtasks as $subtask) {
                $approved[] = $this->approveSubtask($subtask, $reviewer);
            }

            return [
                'approved' => $approved,
                'skipped' => count($uuids) - count($approved),
            ];
        });
    }

    public function rejectTask(Task $task, User $reviewer, string $reason): Task
    {
        $this->assertPending($task->approval_status, 'task');

        return DB::transaction(function () use ($task, $reviewer, $reason): Task {
            $task->update([
                'approval_status' => ApprovalStatus::REJECTED,
                'reviewed_by_id' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->cascadeBundle($task, ApprovalStatus::REJECTED, $reviewer, $reason);

            ActivityLog::create([
                'task_id' => $task->id,
                'user_id' => $reviewer->id,
                'action' => 'task.rejected',
                'field_changed' => 'approval_status',
                'new_value' => $reason,
            ]);

            if ($task->submitted_by_id !== null) {
                $this->notifications->notifyApprovalDecision(
                    $task,
                    (int) $task->submitted_by_id,
                    $reviewer,
                    'task.rejected',
                    $task->title,
                    $reason,
                );
            }

            return $task;
        });
    }

    public function approveSubtask(Subtask $subtask, User $reviewer): Subtask
    {
        $this->assertNotApproved($subtask->approval_status, 'sub-task');

        return DB::transaction(function () use ($subtask, $reviewer): Subtask {
            $subtask->update([
                'approval_status' => ApprovalStatus::APPROVED,
                'reviewed_by_id' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);

            ActivityLog::create([
                'task_id' => $subtask->task_id,
                'subtask_id' => $subtask->id,
                'user_id' => $reviewer->id,
                'action' => 'subtask.approved',
            ]);

            $this->notifySubtaskDecision($subtask, $reviewer, 'subtask.approved');

            return $subtask;
        });
    }

    public function rejectSubtask(Subtask $subtask, User $reviewer, string $reason): Subtask
    {
        $this->assertPending($subtask->approval_status, 'sub-task');

        return DB::transaction(function () use ($subtask, $reviewer, $reason): Subtask {
            $subtask->update([
                'approval_status' => ApprovalStatus::REJECTED,
                'reviewed_by_id' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            ActivityLog::create([
                'task_id' => $subtask->task_id,
                'subtask_id' => $subtask->id,
                'user_id' => $reviewer->id,
                'action' => 'subtask.rejected',
                'field_changed' => 'approval_status',
                'new_value' => $reason,
            ]);

            $this->notifySubtaskDecision($subtask, $reviewer, 'subtask.rejected', $reason);

            return $subtask;
        });
    }

    /**
     * The author revised a rejected proposal and sends it back for review.
     */
    public function resubmitTask(Task $task, User $author): Task
    {
        return DB::transaction(function () use ($task, $author): Task {
            $task->update([
                'approval_status' => ApprovalStatus::PENDING,
                'reviewed_by_id' => null,
                'reviewed_at' => null,
                'rejection_reason' => null,
            ]);

            $this->cascadeBundle($task, ApprovalStatus::PENDING, $author);

            ActivityLog::create([
                'task_id' => $task->id,
                'user_id' => $author->id,
                'action' => 'task.resubmitted',
            ]);

            $this->notifications->notifyTaskReviewRequested($task, $author);

            return $task;
        });
    }

    public function resubmitSubtask(Subtask $subtask, User $author): Subtask
    {
        return DB::transaction(function () use ($subtask, $author): Subtask {
            $subtask->update([
                'approval_status' => ApprovalStatus::PENDING,
                'reviewed_by_id' => null,
                'reviewed_at' => null,
                'rejection_reason' => null,
            ]);

            ActivityLog::create([
                'task_id' => $subtask->task_id,
                'subtask_id' => $subtask->id,
                'user_id' => $author->id,
                'action' => 'subtask.resubmitted',
            ]);

            $this->notifications->notifySubtaskReviewRequested($subtask, $author);

            return $subtask;
        });
    }

    /**
     * Carry a task-level decision to the sub-tasks submitted as part of the same
     * proposal. Sub-tasks a reviewer added while editing inherit the parent's
     * state too (SubtaskService::create keeps them unapproved), so they are
     * matched on state rather than on authorship.
     */
    private function cascadeBundle(
        Task $task,
        ApprovalStatus $status,
        User $actor,
        ?string $reason = null,
    ): void {
        $task->subtasks()
            ->where('approval_status', '!=', ApprovalStatus::APPROVED->value)
            ->update([
                'approval_status' => $status->value,
                'reviewed_by_id' => $status === ApprovalStatus::PENDING ? null : $actor->id,
                'reviewed_at' => $status === ApprovalStatus::PENDING ? null : now(),
                'rejection_reason' => $reason,
            ]);
    }

    private function notifySubtaskDecision(
        Subtask $subtask,
        User $reviewer,
        string $type,
        ?string $reason = null,
    ): void {
        if ($subtask->submitted_by_id === null) {
            return;
        }

        $subtask->loadMissing('task');

        $this->notifications->notifyApprovalDecision(
            $subtask->task,
            (int) $subtask->submitted_by_id,
            $reviewer,
            $type,
            $subtask->name,
            $reason,
        );
    }

    /**
     * Rejecting is a one-way door out of the queue: an already-rejected proposal
     * is back with its author, and approved work is no longer under review. The
     * UI hides the button in both cases; this is the same rule on the wire.
     */
    private function assertPending(?ApprovalStatus $status, string $noun): void
    {
        if ($status === ApprovalStatus::PENDING) {
            return;
        }

        throw ValidationException::withMessages([
            'approval_status' => $status === ApprovalStatus::REJECTED
                ? "This {$noun} has already been rejected — its author has to revise and re-submit it."
                : "This {$noun} is already approved, so it is no longer under review.",
        ]);
    }

    /**
     * Approving stays open to a rejected proposal (a reviewer may edit it and let
     * it through), but approving twice is not a real action.
     */
    private function assertNotApproved(?ApprovalStatus $status, string $noun): void
    {
        if ($status !== ApprovalStatus::APPROVED) {
            return;
        }

        throw ValidationException::withMessages([
            'approval_status' => "This {$noun} is already approved.",
        ]);
    }

    /**
     * @param  Builder<Task|Subtask>  $query
     */
    private function applyState(Builder $query, ?string $state): void
    {
        match ($state) {
            'rejected' => $query->where('approval_status', ApprovalStatus::REJECTED),
            // "all" means everything still in the review loop — approved work has
            // left the queue and belongs on the board, not here.
            'all' => $query->whereIn('approval_status', [
                ApprovalStatus::PENDING->value,
                ApprovalStatus::REJECTED->value,
            ]),
            default => $query->awaitingReview(),
        };
    }
}
