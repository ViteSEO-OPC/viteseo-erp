<?php

declare(strict_types=1);

namespace App\Modules\Projects\Policies;

use App\Models\User;
use App\Modules\Projects\Enums\AssignmentType;
use App\Modules\Projects\Models\Subtask;

class SubtaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('projects.subtasks.view');
    }

    public function view(User $user, Subtask $subtask): bool
    {
        if ($user->can('projects.subtasks.view')) {
            return true;
        }

        // The subtask's assignee may always view it.
        return $subtask->assignee_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('projects.subtasks.create');
    }

    public function update(User $user, Subtask $subtask): bool
    {
        if ($user->can('projects.subtasks.update')) {
            return true;
        }

        // The leader of the parent group task may update the subtask in full.
        if ($this->isParentGroupLeader($user, $subtask)) {
            return true;
        }

        // The subtask's assignee may update it (limited to the status by the
        // controller's field-level RBAC).
        return $subtask->assignee_id === $user->id;
    }

    /**
     * Whether $user leads the group that owns the subtask's (group-assigned) parent task.
     */
    private function isParentGroupLeader(User $user, Subtask $subtask): bool
    {
        $subtask->loadMissing('task.group');
        $task = $subtask->task;

        return $task !== null
            && $task->assignment_type === AssignmentType::GROUP
            && $task->group?->leader_id === $user->id;
    }

    public function delete(User $user, Subtask $subtask): bool
    {
        if ($user->can('projects.subtasks.delete')) {
            return true;
        }

        // The leader of the parent group task may also archive its subtasks.
        return $this->isParentGroupLeader($user, $subtask);
    }
}
