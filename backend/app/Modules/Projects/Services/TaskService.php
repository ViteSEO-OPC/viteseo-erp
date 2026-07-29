<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Models\User;
use App\Modules\Projects\Enums\AssignmentType;
use App\Modules\Projects\Models\ActivityLog;
use App\Modules\Projects\Models\Group;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectStatus;
use App\Modules\Projects\Models\Task;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Task>
     */
    public function list(Project $project, array $filters, ?string $sort, int $perPage, ?int $currentUserId = null): LengthAwarePaginator
    {
        $query = Task::query()
            ->where('project_id', $project->id)
            ->with(['project', 'status', 'assignee', 'group'])
            ->withCount([
                'subtasks',
                // Whether the requesting user is assigned a subtask under this task —
                // lets a member see the parent task of a subtask assigned to them.
                'subtasks as my_subtasks_count' => fn (Builder $q) => $q->where('assignee_id', $currentUserId),
            ]);

        // Eager-load the requesting user's own subtasks (with status) so the
        // member "My Tasks" board can render them as their own cards.
        if ($currentUserId !== null) {
            $query->with([
                // Eager-load closure receives the relation query, not a Builder.
                'subtasks' => fn ($q) => $q
                    ->where('assignee_id', $currentUserId)
                    ->with('status'),
            ]);
        }

        if (isset($filters['status_id']) && $filters['status_id'] !== '') {
            $query->where('status_id', $filters['status_id']);
        }

        if (isset($filters['priority']) && $filters['priority'] !== '') {
            $query->where('priority', $filters['priority']);
        }

        if (isset($filters['assignment_type']) && $filters['assignment_type'] !== '') {
            $query->where('assignment_type', $filters['assignment_type']);
        }

        if (isset($filters['assignee_id']) && $filters['assignee_id'] !== '') {
            $query->where('assignee_id', $filters['assignee_id']);
        }

        if (isset($filters['group_id']) && $filters['group_id'] !== '') {
            $query->where('group_id', $filters['group_id']);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $query->where('title', 'like', '%'.$filters['search'].'%');
        }

        $this->applySort($query, $sort);

        return $query->paginate($perPage);
    }

    /**
     * Eager-load every relation needed to render a single task's detail view.
     */
    public function detail(Task $task): Task
    {
        return $task->load([
            'project',
            'group',
            'assignee',
            'assignedBy',
            'status',
            'subtasks' => fn ($query) => $query->with(['assignee', 'status'])->orderBy('created_at'),
            'comments' => fn ($query) => $query->with('author')->orderBy('created_at', 'desc'),
            'links' => fn ($query) => $query->with('user')->orderBy('created_at', 'desc'),
            'activityLogs' => fn ($query) => $query->with('user')->orderBy('created_at', 'desc'),
        ]);
    }

    /**
     * @param  Builder<Task>  $query
     */
    private function applySort(Builder $query, ?string $sort): void
    {
        $allowed = ['title', 'priority', 'deadline', 'effort_score', 'created_at', 'updated_at'];

        if ($sort === null || $sort === '') {
            $query->orderBy('created_at', 'desc');

            return;
        }

        $direction = 'asc';
        $field = $sort;

        if (str_starts_with($sort, '-')) {
            $direction = 'desc';
            $field = substr($sort, 1);
        }

        if (! in_array($field, $allowed, true)) {
            $query->orderBy('created_at', 'desc');

            return;
        }

        $query->orderBy($field, $direction);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data, User $user): Task
    {
        return DB::transaction(function () use ($project, $data, $user): Task {
            // #4 status scoping: status must belong to this project.
            $this->assertStatusInProject($project, (int) $data['status_id']);

            $assignmentType = $data['assignment_type'] instanceof AssignmentType
                ? $data['assignment_type']->value
                : $data['assignment_type'];

            // #6 project scoping + #1 exclusivity.
            if ($assignmentType === AssignmentType::GROUP->value) {
                $this->assertGroupInProject($project, (int) $data['group_id']);
                $data['assignee_id'] = null;
            } else {
                $data['group_id'] = null;
            }

            // Record who assigned the task (its creator) when it starts with an owner.
            $hasOwner = ($assignmentType === AssignmentType::INDIVIDUAL->value && ! empty($data['assignee_id']))
                || ($assignmentType === AssignmentType::GROUP->value && ! empty($data['group_id']));
            $data['assigned_by_id'] = $hasOwner ? $user->id : null;

            $data['project_id'] = $project->id;

            $task = Task::create($data);

            ActivityLog::create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'action' => 'task.created',
            ]);

            // Notify the assignee when a task is created already assigned to
            // someone other than the creator.
            if ($task->assignment_type === AssignmentType::INDIVIDUAL
                && $task->assignee_id !== null) {
                $this->notifications->notifyTaskAssigned($task, (int) $task->assignee_id, $user);
            }

            return $task;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Task $task, array $data, User $user): Task
    {
        return DB::transaction(function () use ($task, $data, $user): Task {
            $project = $task->project;

            // Determine the effective assignment type after this update.
            $assignmentType = array_key_exists('assignment_type', $data)
                ? ($data['assignment_type'] instanceof AssignmentType
                    ? $data['assignment_type']->value
                    : $data['assignment_type'])
                : $task->assignment_type?->value;

            // Re-validate status scoping if status is changing.
            if (array_key_exists('status_id', $data)) {
                $this->assertStatusInProject($project, (int) $data['status_id']);
            }

            // Re-validate project scoping if the group is (or becomes) relevant.
            if ($assignmentType === AssignmentType::GROUP->value && array_key_exists('group_id', $data)) {
                $this->assertGroupInProject($project, (int) $data['group_id']);
            }

            // A real switch between individual ↔ group (not just re-sending the
            // same type). On such a switch the old owner no longer applies.
            $typeChanged = array_key_exists('assignment_type', $data)
                && $assignmentType !== $task->assignment_type?->value;

            // #1 exclusivity: null out the non-applicable side when known.
            if ($assignmentType === AssignmentType::GROUP->value) {
                if (array_key_exists('assignment_type', $data)) {
                    $data['assignee_id'] = null;
                }
            } elseif ($assignmentType === AssignmentType::INDIVIDUAL->value) {
                if (array_key_exists('assignment_type', $data)) {
                    $data['group_id'] = null;
                }
                // Switching group → individual drops the (implicit) owner: the
                // task becomes unassigned rather than inheriting anyone.
                if ($typeChanged) {
                    $data['assignee_id'] = null;
                }
            }

            $oldStatusId = $task->status_id;
            $oldAssigneeId = $task->assignee_id;
            $oldGroupId = $task->group_id;

            $task->update($data);

            // When the assignment target changes, record who made the change
            // (or clear it when the task becomes unassigned).
            $assignmentChanged = (int) $task->assignee_id !== (int) $oldAssigneeId
                || (int) $task->group_id !== (int) $oldGroupId
                || $typeChanged;
            if ($assignmentChanged) {
                $hasOwner = ($task->assignment_type === AssignmentType::INDIVIDUAL && $task->assignee_id !== null)
                    || ($task->assignment_type === AssignmentType::GROUP && $task->group_id !== null);
                $task->update(['assigned_by_id' => $hasOwner ? $user->id : null]);
            }

            // Notify the new individual assignee when the assignee actually changes
            // to a real user (never the actor — handled in NotificationService).
            if ($task->assignment_type === AssignmentType::INDIVIDUAL
                && $task->assignee_id !== null
                && (int) $task->assignee_id !== (int) $oldAssigneeId) {
                $this->notifications->notifyTaskAssigned($task, (int) $task->assignee_id, $user);
            }

            // Changing the assignment type invalidates every subtask's assignee
            // (a member of the old group, or tied to the old individual owner),
            // so unassign them all — the task and its subtasks start fresh.
            if ($typeChanged) {
                $task->subtasks()->update([
                    'assignee_id' => null,
                    'flag_reason' => null,
                    'flagged_at' => null,
                ]);
            }

            if (array_key_exists('status_id', $data) && (int) $data['status_id'] !== (int) $oldStatusId) {
                ActivityLog::create([
                    'task_id' => $task->id,
                    'user_id' => $user->id,
                    'action' => 'task.status_changed',
                    'field_changed' => 'status_id',
                    'old_value' => (string) $oldStatusId,
                    'new_value' => (string) $data['status_id'],
                ]);

                // Notify the project's managers that a task moved — this is how a
                // PM hears about a member advancing their own task's status.
                $newStatus = ProjectStatus::find((int) $data['status_id']);
                $this->notifications->notifyTaskStatusChanged(
                    $task,
                    $user,
                    $newStatus?->name ?? 'a new status',
                );
            }

            return $task;
        });
    }

    public function delete(Task $task): void
    {
        $task->delete();
    }

    /**
     * #4 A task's status_id must belong to the same project.
     */
    private function assertStatusInProject(Project $project, int $statusId): void
    {
        $belongs = ProjectStatus::query()
            ->whereKey($statusId)
            ->where('project_id', $project->id)
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'status_id' => 'The selected status must belong to the task\'s project.',
            ]);
        }
    }

    /**
     * #6 A group-assigned task's group must belong to the task's project.
     */
    private function assertGroupInProject(Project $project, int $groupId): void
    {
        $belongs = Group::query()
            ->whereKey($groupId)
            ->whereHas('projects', fn ($q) => $q->whereKey($project->id))
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'group_id' => 'The selected group must belong to the task\'s project.',
            ]);
        }
    }
}
