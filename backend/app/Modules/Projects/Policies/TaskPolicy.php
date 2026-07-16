<?php

declare(strict_types=1);

namespace App\Modules\Projects\Policies;

use App\Models\User;
use App\Modules\Projects\Enums\AssignmentType;
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

        // The individual assignee may always view their own task.
        return $task->assignee_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('projects.tasks.create');
    }

    public function update(User $user, Task $task): bool
    {
        if ($user->can('projects.tasks.update')) {
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
        return $user->can('projects.tasks.delete');
    }
}
