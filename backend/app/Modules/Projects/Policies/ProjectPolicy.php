<?php

declare(strict_types=1);

namespace App\Modules\Projects\Policies;

use App\Modules\Projects\Enums\UserRole;
use App\Models\User;
use App\Modules\Projects\Models\Project;

class ProjectPolicy
{
    /** Admin/PM manage everything; members are scoped to their own projects. */
    private function isManager(User $user): bool
    {
        return $user->hasAnyRole(UserRole::managerRoles());
    }

    public function viewAny(User $user): bool
    {
        return $user->can('projects.projects.view');
    }

    public function view(User $user, Project $project): bool
    {
        if ($this->isManager($user)) {
            return true;
        }

        // A member may only view a project they are assigned to.
        return $user->can('projects.projects.view')
            && $project->members()->whereKey($user->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('projects.projects.create');
    }

    public function update(User $user, Project $project): bool
    {
        return $user->can('projects.projects.update');
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->can('projects.projects.delete');
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $user->can('projects.projects.manage_members');
    }
}
