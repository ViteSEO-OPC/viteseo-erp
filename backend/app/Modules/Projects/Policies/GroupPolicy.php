<?php

declare(strict_types=1);

namespace App\Modules\Projects\Policies;

use App\Models\User;
use App\Modules\Projects\Models\Group;

class GroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('projects.groups.view');
    }

    public function view(User $user, Group $group): bool
    {
        if ($user->can('projects.groups.view')) {
            return true;
        }

        // A group's leader or a current member may always view it.
        if ($group->leader_id === $user->id) {
            return true;
        }

        return $group->members()
            ->whereKey($user->id)
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('projects.groups.create');
    }

    public function update(User $user, Group $group): bool
    {
        return $user->can('projects.groups.update');
    }

    public function delete(User $user, Group $group): bool
    {
        return $user->can('projects.groups.delete');
    }

    public function manageMembers(User $user, Group $group): bool
    {
        return $user->can('projects.groups.manage_members');
    }
}
