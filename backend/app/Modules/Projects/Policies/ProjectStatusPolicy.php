<?php

declare(strict_types=1);

namespace App\Modules\Projects\Policies;

use App\Models\User;
use App\Modules\Projects\Models\ProjectStatus;

class ProjectStatusPolicy
{
    /**
     * Used by the status index (class-level authorization — no model instance).
     */
    public function viewAny(User $user): bool
    {
        return $user->can('projects.statuses.view');
    }

    public function view(User $user, ProjectStatus $status): bool
    {
        return $user->can('projects.statuses.view');
    }

    public function create(User $user): bool
    {
        return $user->can('projects.statuses.create');
    }

    public function update(User $user, ProjectStatus $status): bool
    {
        return $user->can('projects.statuses.update');
    }

    public function delete(User $user, ProjectStatus $status): bool
    {
        return $user->can('projects.statuses.delete');
    }
}
