<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Models\User;
use App\Modules\Projects\Enums\StatusKey;
use App\Modules\Projects\Enums\UserRole;
use App\Modules\Projects\Models\Group;
use App\Modules\Projects\Models\Subtask;
use App\Modules\Projects\Models\Task;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The Workload Analysis page: who is carrying what, and how heavy it is.
 *
 * TWO different notions of "heavy" live here, and they are deliberately not the
 * same thing:
 *
 *  - A TASK's category comes from its own effort score (Light 1-3, Moderate 4-7,
 *    Heavy 8-10). That drives the four KPI cards and the table's Category badge.
 *  - A MEMBER's level comes from their SUMMED effort against the editable
 *    capacity bands (see WorkloadSetting). That drives the chart's colouring and
 *    the existing assignment indicator.
 *
 * The unit of work is the same one {@see WorkloadService} already aggregates, so
 * the page can never disagree with the indicator in the task modal:
 *   - a task assigned to the member that has NO approved sub-tasks, or
 *   - a sub-task assigned to the member.
 * A parent task WITH sub-tasks contributes nothing itself — its effort is
 * represented by the sub-tasks, which each belong to their own assignee.
 *
 * Only ACTIVE (To Do / In Progress) and APPROVED work counts. Finished work is
 * not load you are still carrying, and an unapproved proposal is not yet work at
 * all. Completion stats on the member page count Done separately and say so.
 */
class WorkloadAnalysisService
{
    public function __construct(private readonly WorkloadService $workload) {}

    /**
     * A single row of assigned work, flattened across tasks and sub-tasks.
     *
     * @return array{
     *   id: string, kind: string, title: string, project_id: ?string,
     *   project_name: ?string, task_id: ?string, effort_score: int,
     *   category: string, priority: ?string, deadline: ?string,
     *   assignee_id: ?int, assignee_name: ?string, group_id: ?string,
     *   group_name: ?string, status_key: ?string
     * }
     */
    private function taskRow(Task $task): array
    {
        return [
            'id' => (string) $task->uuid,
            'kind' => 'task',
            'title' => (string) $task->title,
            'project_id' => $task->project?->uuid,
            'project_name' => $task->project?->name,
            'task_id' => (string) $task->uuid,
            'effort_score' => (int) ($task->effort_score ?? 0),
            'category' => $this->categoryOf($task->effort_score),
            'priority' => $task->priority?->value,
            'deadline' => $task->deadline?->toIso8601String(),
            'assignee_id' => $task->assignee_id !== null ? (int) $task->assignee_id : null,
            'assignee_name' => $task->assignee?->name,
            'group_id' => $task->group?->uuid,
            'group_name' => $task->group?->name,
            'status_key' => $task->status?->key?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subtaskRow(Subtask $subtask): array
    {
        $task = $subtask->task;

        return [
            'id' => (string) $subtask->uuid,
            'kind' => 'subtask',
            'title' => (string) $subtask->name,
            'project_id' => $task?->project?->uuid,
            'project_name' => $task?->project?->name,
            'task_id' => $task?->uuid,
            'parent_title' => $task?->title,
            'effort_score' => (int) $subtask->effort_score,
            'category' => $this->categoryOf($subtask->effort_score),
            'priority' => $subtask->priority?->value,
            'deadline' => $subtask->deadline?->toIso8601String(),
            'assignee_id' => $subtask->assignee_id !== null ? (int) $subtask->assignee_id : null,
            'assignee_name' => $subtask->assignee?->name,
            // A sub-task inherits its parent's group for group aggregation.
            'group_id' => $task?->group?->uuid,
            'group_name' => $task?->group?->name,
            'status_key' => $subtask->status?->key?->value,
        ];
    }

    /**
     * Effort score → task category, per the KPI card headers.
     *
     * A null or zero score (a group parent, or work nobody sized) has no real
     * weight, so it falls in Light rather than inventing a fourth band.
     */
    public function categoryOf(?int $effort): string
    {
        $score = (int) ($effort ?? 0);

        if ($score <= 3) {
            return 'light';
        }

        return $score <= 7 ? 'moderate' : 'heavy';
    }

    /**
     * Every active, approved work row visible to $viewer.
     *
     * A manager sees the whole platform; a member sees only their own work —
     * enforced here rather than in the controller so no caller can widen it.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(User $viewer, array $filters = []): Collection
    {
        $active = [StatusKey::TODO->value, StatusKey::IN_PROGRESS->value];
        $isManager = $viewer->hasAnyRole(UserRole::managerRoles());

        $tasks = Task::query()
            ->approved()
            // Work with an owner of either kind: an individual assignee, or a
            // group (a group task carries no assignee_id — its effort belongs to
            // the group, exactly as WorkloadService::effortForGroup counts it).
            ->where(fn ($q) => $q->whereNotNull('assignee_id')->orWhereNotNull('group_id'))
            ->whereHas('status', fn ($q) => $q->whereIn('key', $active))
            // Mirrors WorkloadService: a parent with approved sub-tasks is
            // represented by those sub-tasks, never counted twice.
            ->whereDoesntHave('subtasks', fn ($q) => $q->approved())
            ->when(! $isManager, fn ($q) => $q->where('assignee_id', $viewer->id))
            ->with(['project', 'status', 'assignee', 'group'])
            ->get()
            ->map(fn (Task $t): array => $this->taskRow($t));

        $subtasks = Subtask::query()
            ->approved()
            ->whereNotNull('assignee_id')
            ->whereHas('task', fn ($q) => $q->approved())
            ->whereHas('status', fn ($q) => $q->whereIn('key', $active))
            ->when(! $isManager, fn ($q) => $q->where('assignee_id', $viewer->id))
            ->with(['task.project', 'task.group', 'status', 'assignee'])
            ->get()
            ->map(fn (Subtask $s): array => $this->subtaskRow($s));

        return $this->applyFilters($tasks->concat($subtasks)->values(), $filters);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFilters(Collection $rows, array $filters): Collection
    {
        if (! empty($filters['project_id'])) {
            $rows = $rows->where('project_id', $filters['project_id']);
        }

        if (! empty($filters['group_id'])) {
            $rows = $rows->where('group_id', $filters['group_id']);
        }

        if (! empty($filters['assignee_id'])) {
            $rows = $rows->where('assignee_id', (int) $filters['assignee_id']);
        }

        if (! empty($filters['category'])) {
            $rows = $rows->where('category', $filters['category']);
        }

        if (! empty($filters['priority'])) {
            $rows = $rows->where('priority', $filters['priority']);
        }

        if (! empty($filters['search'])) {
            $needle = mb_strtolower((string) $filters['search']);
            $rows = $rows->filter(
                fn (array $r): bool => str_contains(mb_strtolower((string) $r['title']), $needle),
            );
        }

        return $rows->values();
    }

    /**
     * The four KPI cards: total active work plus a count per task category.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{total: int, light: int, moderate: int, heavy: int}
     */
    public function totals(Collection $rows): array
    {
        return [
            'total' => $rows->count(),
            'light' => $rows->where('category', 'light')->count(),
            'moderate' => $rows->where('category', 'moderate')->count(),
            'heavy' => $rows->where('category', 'heavy')->count(),
        ];
    }

    /**
     * One chart bar per member: their effort split by task category (the stack
     * segments) plus the total and the band that total falls in.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function byMember(Collection $rows): array
    {
        return $rows
            // Group-assigned work has no individual owner; it belongs on the
            // Group view, not as a phantom "Unassigned" bar next to real people.
            ->filter(fn (array $r): bool => $r['assignee_id'] !== null)
            ->groupBy('assignee_id')
            ->map(function (Collection $items, $assigneeId): array {
                $total = (int) $items->sum('effort_score');

                return [
                    'id' => (int) $assigneeId,
                    'name' => (string) ($items->first()['assignee_name'] ?? 'Unassigned'),
                    'light' => (int) $items->where('category', 'light')->sum('effort_score'),
                    'moderate' => (int) $items->where('category', 'moderate')->sum('effort_score'),
                    'heavy' => (int) $items->where('category', 'heavy')->sum('effort_score'),
                    'total_effort' => $total,
                    'task_count' => $items->count(),
                    'level' => $this->workload->level($total),
                ];
            })
            ->sortByDesc('total_effort')
            ->values()
            ->all();
    }

    /**
     * One row per group: the same aggregation over the group's work.
     *
     * Rows with no group (individually-assigned work) are excluded — a group
     * view that silently folded them into an "Ungrouped" bucket would misreport
     * how loaded the actual groups are.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function byGroup(Collection $rows): array
    {
        $memberCounts = Group::query()
            ->withCount('members')
            ->get()
            ->mapWithKeys(fn (Group $g): array => [
                (string) $g->uuid => (int) $g->members_count + ($g->leader_id !== null ? 1 : 0),
            ]);

        return $rows
            ->filter(fn (array $r): bool => $r['group_id'] !== null)
            ->groupBy('group_id')
            ->map(function (Collection $items, $groupId) use ($memberCounts): array {
                $total = (int) $items->sum('effort_score');

                return [
                    'id' => (string) $groupId,
                    'name' => (string) ($items->first()['group_name'] ?? 'Group'),
                    'light' => (int) $items->where('category', 'light')->sum('effort_score'),
                    'moderate' => (int) $items->where('category', 'moderate')->sum('effort_score'),
                    'heavy' => (int) $items->where('category', 'heavy')->sum('effort_score'),
                    'total_effort' => $total,
                    'task_count' => $items->count(),
                    'member_count' => (int) ($memberCounts[$groupId] ?? 0),
                    'level' => $this->workload->level($total),
                ];
            })
            ->sortByDesc('total_effort')
            ->values()
            ->all();
    }

    /**
     * The member page's completion panel. Unlike everything else here this DOES
     * count Done work — that is the whole point of a completion percentage — and
     * it spans the member's tasks and sub-tasks across every project.
     *
     * @return array{todo: int, in_progress: int, done: int, total: int, percent: int}
     */
    public function completionFor(User $user): array
    {
        $countBy = function (string $key) use ($user): int {
            $tasks = Task::query()
                ->approved()
                ->where('assignee_id', $user->id)
                ->whereDoesntHave('subtasks', fn ($q) => $q->approved())
                ->whereHas('status', fn ($q) => $q->where('key', $key))
                ->count();

            $subtasks = Subtask::query()
                ->approved()
                ->whereHas('task', fn ($q) => $q->approved())
                ->where('assignee_id', $user->id)
                ->whereHas('status', fn ($q) => $q->where('key', $key))
                ->count();

            return $tasks + $subtasks;
        };

        $todo = $countBy(StatusKey::TODO->value);
        $inProgress = $countBy(StatusKey::IN_PROGRESS->value);
        $done = $countBy(StatusKey::DONE->value);
        $total = $todo + $inProgress + $done;

        return [
            'todo' => $todo,
            'in_progress' => $inProgress,
            'done' => $done,
            'total' => $total,
            'percent' => $total > 0 ? (int) round(($done / $total) * 100) : 0,
        ];
    }

    /**
     * Manually paginate the flattened rows.
     *
     * The rows come from two tables merged in PHP, so there is no single query
     * to LIMIT. At this module's scale (a sprint's worth of active work) that is
     * a fine trade for keeping the task/sub-task union readable; revisit with a
     * SQL UNION if a deployment ever carries tens of thousands of active rows.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return LengthAwarePaginator<array<string, mixed>>
     */
    public function paginate(Collection $rows, int $perPage, int $page, ?string $sort = null): LengthAwarePaginator
    {
        $sorted = $this->applySort($rows, $sort);

        return new LengthAwarePaginator(
            $sorted->forPage($page, $perPage)->values()->all(),
            $sorted->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()],
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function applySort(Collection $rows, ?string $sort): Collection
    {
        $allowed = ['title', 'project_name', 'effort_score', 'category', 'priority', 'deadline', 'assignee_name'];

        $field = $sort !== null && $sort !== '' ? ltrim($sort, '-') : 'effort_score';
        $desc = $sort === null || $sort === '' || str_starts_with($sort, '-');

        if (! in_array($field, $allowed, true)) {
            $field = 'effort_score';
            $desc = true;
        }

        return $desc
            ? $rows->sortByDesc($field)->values()
            : $rows->sortBy($field)->values();
    }
}
