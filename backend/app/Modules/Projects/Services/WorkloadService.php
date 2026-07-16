<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Models\User;
use App\Modules\Projects\Enums\StatusKey;
use App\Modules\Projects\Models\Group;
use App\Modules\Projects\Models\Subtask;
use App\Modules\Projects\Models\Task;

/**
 * Live "Member Workload Indicator": aggregates a member's *active* effort score
 * from the tasks and subtasks currently assigned to them.
 *
 * Rules (see the Member Workload Indicator spec):
 *  - Only Todo / In Progress work counts; every other status is ignored.
 *  - A parent task WITHOUT subtasks contributes its own effort score.
 *  - A parent task WITH subtasks contributes 0 — its effort is disregarded and
 *    only the subtasks count (avoids double-counting).
 *  - Each subtask counts only toward its own assignee's workload.
 *  - Workload is advisory only; it never blocks assignment.
 */
class WorkloadService
{
    /**
     * @return array{user_id:int, effort_score:int, level:string, thresholds:array{light:int,moderate:int}}
     */
    public function summary(User $user): array
    {
        $score = $this->effortFor($user->id);

        return [
            'user_id' => $user->id,
            'effort_score' => $score,
            'level' => $this->level($score),
            'thresholds' => $this->thresholds(),
        ];
    }

    /**
     * Aggregated active effort score for a member.
     */
    public function effortFor(int $userId): int
    {
        $active = [StatusKey::TODO->value, StatusKey::IN_PROGRESS->value];

        // Parent tasks assigned to the member, in an active status, that have no
        // subtasks — these contribute their own effort. Tasks that do have
        // subtasks contribute 0 (their effort is ignored in favour of subtasks).
        $taskEffort = (int) Task::query()
            ->where('assignee_id', $userId)
            ->whereHas('status', fn ($q) => $q->whereIn('key', $active))
            ->whereDoesntHave('subtasks')
            ->sum('effort_score');

        // Subtasks assigned to the member, in an active status — each counts
        // only toward its own assignee, regardless of who owns the parent task.
        $subtaskEffort = (int) Subtask::query()
            ->where('assignee_id', $userId)
            ->whereHas('status', fn ($q) => $q->whereIn('key', $active))
            ->sum('effort_score');

        return $taskEffort + $subtaskEffort;
    }

    /**
     * Aggregated active workload for a group — the same rules as a member, but
     * scoped to the group's own work. Advisory / indication only.
     *
     * @return array{group_id:string, effort_score:int, level:string, thresholds:array{light:int,moderate:int}}
     */
    public function groupSummary(Group $group): array
    {
        $score = $this->effortForGroup($group->id);

        return [
            'group_id' => $group->uuid,
            'effort_score' => $score,
            'level' => $this->level($score),
            'thresholds' => $this->thresholds(),
        ];
    }

    /**
     * Aggregated active effort score for a group's assigned work.
     *
     * Same aggregation as a member, scoped to the group: group tasks in an
     * active status without subtasks contribute their own effort, while the
     * subtasks of the group's tasks contribute (their parent task counts 0).
     */
    public function effortForGroup(int $groupId): int
    {
        $active = [StatusKey::TODO->value, StatusKey::IN_PROGRESS->value];

        $taskEffort = (int) Task::query()
            ->where('group_id', $groupId)
            ->whereHas('status', fn ($q) => $q->whereIn('key', $active))
            ->whereDoesntHave('subtasks')
            ->sum('effort_score');

        $subtaskEffort = (int) Subtask::query()
            ->whereHas('task', fn ($q) => $q->where('group_id', $groupId))
            ->whereHas('status', fn ($q) => $q->whereIn('key', $active))
            ->sum('effort_score');

        return $taskEffort + $subtaskEffort;
    }

    /**
     * Classify an aggregated score into a workload level.
     */
    public function level(int $score): string
    {
        $thresholds = $this->thresholds();

        if ($score <= $thresholds['light']) {
            return 'light';
        }

        if ($score <= $thresholds['moderate']) {
            return 'moderate';
        }

        return 'heavy';
    }

    /**
     * Configured (inclusive) upper bounds for the Light and Moderate levels.
     *
     * @return array{light:int, moderate:int}
     */
    public function thresholds(): array
    {
        return [
            'light' => (int) config('workload.thresholds.light', 20),
            'moderate' => (int) config('workload.thresholds.moderate', 40),
        ];
    }
}
