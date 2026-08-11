<?php

declare(strict_types=1);

namespace App\Modules\Projects\Policies;

use App\Models\User;
use App\Modules\Projects\Enums\AssignmentType;
use App\Modules\Projects\Enums\StatusKey;
use App\Modules\Projects\Models\Task;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('projects.tasks.view');
    }

    public function view(User $user, Task $task): bool
    {
        if ($user->can('projects.tasks.view')) {
            return true;
        }

        // The author of a proposal may always follow it through review.
        if ((int) $task->submitted_by_id === (int) $user->id) {
            return true;
        }

        // The individual assignee may always view their own task.
        return $task->assignee_id === $user->id;
    }

    /**
     * `.create` is the manager's direct create; `.propose` is a member submitting
     * work for review. Both land in TaskController::store, which decides which
     * one this is and stamps the review state accordingly.
     */
    public function create(User $user): bool
    {
        return $user->can('projects.tasks.create') || $user->can('projects.tasks.propose');
    }

    public function update(User $user, Task $task): bool
    {
        if ($user->can('projects.tasks.update')) {
            return true;
        }

        // The author may keep revising their own proposal until it is approved
        // (that is how a rejected task gets fixed and resubmitted).
        if ($task->isEditableProposalFor($user->id)) {
            return true;
        }

        // Likewise the poster of an open task, until someone claims it.
        if ($task->isEditableOpenPostFor($user->id)) {
            return true;
        }

        // The individual assignee may update their own task.
        if ($task->assignee_id === $user->id) {
            return true;
        }

        // On a group task, only the group's leader may update the parent task
        // (the controller limits non-managers to changing the status only).
        // Regular group members work through their own subtasks, not the parent.
        if ($task->assignment_type === AssignmentType::GROUP) {
            $task->loadMissing('group');
            $group = $task->group;

            return $group !== null && $group->leader_id === $user->id;
        }

        return false;
    }

    public function delete(User $user, Task $task): bool
    {
        if ($user->can('projects.tasks.delete')) {
            return true;
        }

        // Withdrawing your own proposal before it is approved is not a delete of
        // real project work — nor is taking back an open task nobody claimed.
        return $task->isEditableProposalFor($user->id)
            || $task->isEditableOpenPostFor($user->id);
    }

    /**
     * Take an open task from the pool.
     *
     * Deliberately NOT scoped to the task's project roster: the pool only earns
     * its keep when someone from another pod can pick work up, and the claim
     * itself puts them on the project (see TaskService::claim).
     *
     * Permission only — whether the task is still OPEN is state, not authority,
     * and it is decided by the conditional UPDATE in TaskService::claim(). That
     * keeps "someone beat me to it" a 409 whether the other claim landed a second
     * ago or in the same millisecond, instead of a 403 that reads as "you may not
     * claim things".
     */
    public function claim(User $user, Task $task): bool
    {
        return $user->can('projects.tasks.claim');
    }

    /**
     * Hand a task back to the pool.
     */
    public function release(User $user, Task $task): bool
    {
        // Nothing to hand back; and an unapproved proposal is withdrawn (deleted)
        // by its author rather than released.
        if ($task->assignee_id === null || $task->isProposal()) {
            return false;
        }

        // A manager may always take work off someone.
        if ($user->can('projects.tasks.update')) {
            return true;
        }

        // The assignee may drop it while they have not started. Once it has moved
        // off To Do, handing it back is a PM's call — someone is mid-flight.
        $task->loadMissing('status');

        return (int) $task->assignee_id === (int) $user->id
            && ($task->status === null || $task->status->key === StatusKey::TODO);
    }

    /**
     * Review a member's proposal (approve / reject) — PM/Admin only.
     */
    public function approve(User $user, ?Task $task = null): bool
    {
        return $user->can('projects.tasks.approve');
    }

    /**
     * Re-submit a rejected proposal — its author only, and only while unapproved.
     */
    public function resubmit(User $user, Task $task): bool
    {
        return $task->isEditableProposalFor($user->id);
    }
}
