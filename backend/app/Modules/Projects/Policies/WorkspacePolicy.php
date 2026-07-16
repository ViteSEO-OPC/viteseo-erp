<?php

declare(strict_types=1);

namespace App\Modules\Projects\Policies;

use App\Models\User;
use App\Modules\Projects\Models\Workspace;

class WorkspacePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('projects.workspaces.view');
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $user->can('projects.workspaces.view');
    }

    public function create(User $user): bool
    {
        return $user->can('projects.workspaces.create');
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $user->can('projects.workspaces.update');
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $user->can('projects.workspaces.delete');
    }
}
