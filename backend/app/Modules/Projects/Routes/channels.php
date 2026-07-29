<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Projects Broadcast Channels
|--------------------------------------------------------------------------
|
| Private channels for real-time collaboration. Authorization mirrors the
| REST layer exactly so channel access can never drift from API access.
|
| `user.{userId}` used to be declared here. It is an identity channel shared by
| every module, so it now lives in the platform's routes/channels.php — Projects
| still receives its notifications on it, unchanged.
|
*/

// project.{projectUuid} — task/comment/status/subtask events for one project.
// Authorized by ProjectPolicy::view (managers see all; members via pm_project_members).
Broadcast::channel('project.{projectUuid}', function (User $user, string $projectUuid): bool {
    $project = Project::query()->where('uuid', $projectUuid)->first();

    return $project !== null && $user->can('view', $project);
});
