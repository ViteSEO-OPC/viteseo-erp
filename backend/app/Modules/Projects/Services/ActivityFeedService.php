<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Models\User;
use App\Modules\Projects\Enums\UserRole;
use App\Modules\Projects\Models\ActivityLog;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectStatus;
use App\Modules\Projects\Models\Workspace;
use App\Modules\Projects\Support\PublicId;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The cross-project "Recent Activity" feed (workspace page + PM dashboard).
 *
 * Deliberately narrower than the activity log itself: only TASK and SUB-TASK
 * events appear. Comments, links, note edits and project/workspace admin are
 * their own streams and would drown the work signal this feed exists to show.
 *
 * Visibility mirrors the list endpoints exactly (see {@see SearchService}):
 * managers see every project, members only the projects they belong to — and an
 * unapproved proposal's history stays with its author until it is approved.
 */
class ActivityFeedService
{
    /**
     * The actions this feed carries. An allow-list rather than a "not comments"
     * exclusion, so a future log action has to be added here on purpose instead
     * of silently appearing in everyone's feed.
     */
    public const FEED_ACTIONS = [
        'task.created',
        'task.status_changed',
        'task.claimed',
        'task.released',
        'task.approved',
        'task.rejected',
        'task.resubmitted',
        'subtask.created',
        'subtask.status_changed',
        'subtask.approved',
        'subtask.rejected',
        'subtask.resubmitted',
    ];

    /**
     * @param  array<string, mixed>  $filters  workspace_id / project_id, as public UUIDs
     */
    public function paginate(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $userId = (int) $user->id;
        $isManager = $user->hasAnyRole(UserRole::managerRoles());

        $query = ActivityLog::query()
            ->whereIn('action', self::FEED_ACTIONS)
            ->with(['user', 'task.project', 'subtask'])
            // A log is reachable only through its task, so the task carries both
            // the roster check and the review-state check.
            ->whereHas('task', function (Builder $q) use ($userId, $isManager): void {
                $q->visibleTo($userId);

                if (! $isManager) {
                    $q->whereHas('project.members', fn (Builder $m) => $m->whereKey($userId));
                }
            })
            // Sub-task rows carry their own review state — a member's pending
            // sub-task proposal under an approved task is still only theirs.
            ->where(fn (Builder $q) => $q
                ->whereNull('subtask_id')
                ->orWhereHas('subtask', fn (Builder $s) => $s->visibleTo($userId)));

        if (! empty($filters['workspace_id'])) {
            $workspaceId = PublicId::toInternalId(Workspace::class, (string) $filters['workspace_id']);
            $query->whereHas('task.project', fn (Builder $q) => $q->where('workspace_id', $workspaceId));
        }

        if (! empty($filters['project_id'])) {
            $projectId = PublicId::toInternalId(Project::class, (string) $filters['project_id']);
            $query->whereHas('task', fn (Builder $q) => $q->where('project_id', $projectId));
        }

        // created_at has second precision, so a burst of logs written in the same
        // second would otherwise come back in an arbitrary order.
        $logs = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        $this->attachStatusNames($logs->getCollection());

        return $logs;
    }

    /**
     * Status changes are logged as raw status ids. Resolve them for the page in
     * one query so the feed can say "moved to In Review" rather than "to 7".
     *
     * @param  Collection<int, ActivityLog>  $logs
     */
    private function attachStatusNames(Collection $logs): void
    {
        $ids = $logs
            ->filter(fn (ActivityLog $log) => $log->field_changed === 'status_id')
            ->flatMap(fn (ActivityLog $log) => [$log->old_value, $log->new_value])
            ->filter(fn (?string $value) => $value !== null && $value !== '')
            ->map(fn (string $value) => (int) $value)
            ->unique();

        $names = $ids->isEmpty()
            ? collect()
            : ProjectStatus::query()->whereKey($ids->all())->pluck('name', 'id');

        foreach ($logs as $log) {
            if ($log->field_changed !== 'status_id') {
                continue;
            }

            $log->setAttribute('old_status_name', $names->get((int) $log->old_value));
            $log->setAttribute('new_status_name', $names->get((int) $log->new_value));
        }
    }
}
