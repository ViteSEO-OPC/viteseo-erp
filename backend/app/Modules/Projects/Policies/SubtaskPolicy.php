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

        // The author of a proposal may always follow it through review.
        if ((int) $subtask->submitted_by_id === (int) $user->id) {
            return true;
        }

        // The subtask's assignee may always view it.
        return $subtask->assignee_id === $user->id;
    }

    /**
     * `.create` is the direct create; `.propose` is a member submitting a subtask
     * for review. SubtaskController::store decides which one applies and also
     * checks that a proposer actually owns the parent work.
     */
    public function create(User $user): bool
    {
        return $user->can('projects.subtasks.create') || $user->can('projects.subtasks.propose');
    }

    public function update(User $user, Subtask $subtask): bool
    {
        if ($user->can('projects.subtasks.update')) {
            return true;
        }

        // The author may keep revising their own proposal until it is approved.
        if ($subtask->isEditableProposalFor($user->id)) {
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
        if ($this->isParentGroupLeader($user, $subtask)) {
            return true;
        }

        // Withdrawing your own unapproved proposal is not a delete of real work.
        return $subtask->isEditableProposalFor($user->id);
    }

    /**
     * Review a member's proposal (approve / reject) — PM/Admin only.
     */
    public function approve(User $user, ?Subtask $subtask = null): bool
    {
        return $user->can('projects.subtasks.approve');
    }

    /**
     * Re-submit a rejected proposal — its author only, and only while unapproved.
     */
    public function resubmit(User $user, Subtask $subtask): bool
    {
        return $subtask->isEditableProposalFor($user->id);
    }
}
