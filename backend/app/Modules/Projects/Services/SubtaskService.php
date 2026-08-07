<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Models\User;
use App\Modules\Projects\Enums\ApprovalStatus;
use App\Modules\Projects\Enums\AssignmentType;
use App\Modules\Projects\Enums\FlagReason;
use App\Modules\Projects\Models\ActivityLog;
use App\Modules\Projects\Models\ProjectStatus;
use App\Modules\Projects\Models\Subtask;
use App\Modules\Projects\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubtaskService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Task $task, array $data, User $user): Subtask
    {
        return DB::transaction(function () use ($task, $data, $user): Subtask {
            // R4 — status scoping: the status must belong to the task's project.
            $this->assertStatusInProject((int) $data['status_id'], $task);

            // Subtasks may be unassigned; membership (R5) only applies when an
            // assignee is given.
            $assigneeId = isset($data['assignee_id']) && $data['assignee_id'] !== null && $data['assignee_id'] !== ''
                ? (int) $data['assignee_id']
                : null;

            [$flagReason, $flaggedAt] = $assigneeId !== null
                ? $this->resolveAssigneeMembership($task, $assigneeId)
                : [null, null];

            $subtask = new Subtask;
            $subtask->task_id = $task->id;
            $subtask->name = $data['name'];
            $subtask->description = $data['description'] ?? null;
            $subtask->assignee_id = $assigneeId;
            $subtask->status_id = (int) $data['status_id'];
            $subtask->priority = $data['priority'];
            $subtask->effort_score = (int) $data['effort_score'];
            $subtask->deadline = $data['deadline'] ?? null;
            $subtask->flag_reason = $flagReason;
            $subtask->flagged_at = $flaggedAt;

            // Review state. A subtask can never be more approved than its parent:
            // one added to a task still awaiting review inherits that review — so a
            // reviewer's own additions to a proposal don't go live ahead of it.
            $subtask->approval_status = $task->isProposal()
                ? $task->approval_status
                : ($data['approval_status'] ?? ApprovalStatus::APPROVED);
            $subtask->submitted_by_id = $data['submitted_by_id']
                ?? ($task->isProposal() ? $task->submitted_by_id : null);
            $subtask->save();

            ActivityLog::create([
                'task_id' => $task->id,
                'subtask_id' => $subtask->id,
                'user_id' => $user->id,
                'action' => 'subtask.created',
            ]);

            return $subtask;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Subtask $subtask, array $data, User $user): Subtask
    {
        return DB::transaction(function () use ($subtask, $data, $user): Subtask {
            // R4 — status scoping: a changed status must still belong to the task's project.
            if (array_key_exists('status_id', $data)) {
                $subtask->loadMissing('task');
                $this->assertStatusInProject((int) $data['status_id'], $subtask->task);
            }

            $oldStatusId = $subtask->status_id;

            $subtask->fill($data);
            $statusChanged = $subtask->isDirty('status_id');
            $subtask->save();

            if ($statusChanged) {
                ActivityLog::create([
                    'task_id' => $subtask->task_id,
                    'subtask_id' => $subtask->id,
                    'user_id' => $user->id,
                    'action' => 'subtask.status_changed',
                    'field_changed' => 'status_id',
                    'old_value' => $oldStatusId !== null ? (string) $oldStatusId : null,
                    'new_value' => (string) $subtask->status_id,
                ]);
            }

            return $subtask;
        });
    }

    public function delete(Subtask $subtask): void
    {
        $subtask->delete();
    }

    /**
     * R4 — the given status must belong to the same project as the task.
     */
    private function assertStatusInProject(int $statusId, Task $task): void
    {
        $exists = ProjectStatus::query()
            ->whereKey($statusId)
            ->where('project_id', $task->project_id)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'status_id' => 'The selected status must belong to the task\'s project.',
            ]);
        }
    }

    /**
     * R5 — resolve the assignee's membership relative to the parent task.
     *
     * Group-assigned: assignee must be the group's leader or a current group member;
     * otherwise the subtask is flagged (not rejected).
     * Individually-assigned: assignee must be a member of the task's project; otherwise 422.
     *
     * @return array{0: ?FlagReason, 1: ?Carbon}
     */
    private function resolveAssigneeMembership(Task $task, int $assigneeId): array
    {
        if ($task->assignment_type === AssignmentType::GROUP) {
            $task->loadMissing('group');
            $group = $task->group;

            $isMember = $group !== null
                && ($group->leader_id === $assigneeId
                    || $group->members()->whereKey($assigneeId)->exists());

            if (! $isMember) {
                return [FlagReason::ASSIGNEE_LEFT_GROUP, now()];
            }

            return [null, null];
        }

        // INDIVIDUAL — assignee must be a member of the task's project. Team
        // membership lives at the project level (pm_project_members); the
        // workspace is only a grouping of projects.
        $task->loadMissing('project');
        $project = $task->project;

        $isMember = $project !== null
            && $project->members()->whereKey($assigneeId)->exists();

        if (! $isMember) {
            throw ValidationException::withMessages([
                'assignee_id' => 'The selected assignee must be a member of the task\'s project.',
            ]);
        }

        return [null, null];
    }
}
