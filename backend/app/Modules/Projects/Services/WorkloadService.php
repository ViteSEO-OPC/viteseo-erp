<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Models\User;
use App\Modules\Projects\Enums\StatusKey;
use App\Modules\Projects\Models\Group;
use App\Modules\Projects\Models\Subtask;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\WorkloadSetting;

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
    /** @var array{light:int, moderate:int, max:int}|null */
    private ?array $thresholds = null;

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
        // Unapproved proposals are not work yet, so they never count here.
        $taskEffort = (int) Task::query()
            ->approved()
            ->where('assignee_id', $userId)
            ->whereHas('status', fn ($q) => $q->whereIn('key', $active))
            ->whereDoesntHave('subtasks', fn ($q) => $q->approved())
            ->sum('effort_score');

        // Subtasks assigned to the member, in an active status — each counts
        // only toward its own assignee, regardless of who owns the parent task.
        $subtaskEffort = (int) Subtask::query()
            ->approved()
            // A subtask under a task still awaiting review is not live work either.
            ->whereHas('task', fn ($q) => $q->approved())
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
            ->approved()
            ->where('group_id', $groupId)
            ->whereHas('status', fn ($q) => $q->whereIn('key', $active))
            ->whereDoesntHave('subtasks', fn ($q) => $q->approved())
            ->sum('effort_score');

        $subtaskEffort = (int) Subtask::query()
            ->approved()
            ->whereHas('task', fn ($q) => $q->approved()->where('group_id', $groupId))
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
     * Inclusive upper bounds for the Light and Moderate levels, plus the top of
     * the effort range the bands are drawn against.
     *
     * These are editable by a PM/Admin from the Workload Analysis page and are
     * read from the single WorkloadSetting row, so the level shown here, on the
     * analysis chart and in the task modal's assignment indicator can never
     * disagree. The row is seeded from config on first use, so an install that
     * never touches the modal keeps its configured behaviour.
     *
     * Memoised per request: `level()` is called once per member per chart.
     *
     * @return array{light:int, moderate:int, max:int}
     */
    public function thresholds(): array
    {
        if ($this->thresholds !== null) {
            return $this->thresholds;
        }

        $setting = WorkloadSetting::current();

        return $this->thresholds = [
            'light' => $setting->light_max,
            'moderate' => $setting->moderate_max,
            'max' => $setting->max_capacity,
        ];
    }
}
