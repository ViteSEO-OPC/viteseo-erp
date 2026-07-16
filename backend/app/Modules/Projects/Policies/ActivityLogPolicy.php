<?php

declare(strict_types=1);

namespace App\Modules\Projects\Policies;

use App\Models\User;
use App\Modules\Projects\Models\ActivityLog;

class ActivityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('projects.activity_logs.view');
    }

    public function view(User $user, ActivityLog $activityLog): bool
    {
        return $user->can('projects.activity_logs.view');
    }
}
