<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Models\User;
use App\Modules\Projects\Enums\ApprovalStatus;
use App\Modules\Projects\Enums\AssignmentType;
use App\Modules\Projects\Enums\StatusKey;
use App\Modules\Projects\Enums\TaskPriority;
use App\Modules\Projects\Models\Group;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectStatus;
use App\Modules\Projects\Requests\StoreSubtaskRequest;
use App\Modules\Projects\Requests\StoreTaskRequest;
use App\Modules\Projects\Support\PublicId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bulk task import: takes the JSON an assistant produced from TaskImportBrief,
 * resolves the human names in it against THIS project, validates every task and
 * sub-task, and — on commit — creates them all in one transaction.
 *
 * Two passes, one set of rules. `preview()` reports what would happen and never
 * writes; `commit()` re-runs the same validation over the (possibly corrected)
 * plan the client sends back, because the preview response is not a promise —
 * the roster or the board may have moved underneath it, and the client's copy is
 * editable by design.
 *
 * Row identity is a synthetic `ref` (`t3`, `t3s1`). The client addresses rows by
 * it when applying a bulk remap, so nothing depends on matching raw strings twice.
 *
 * Synchronous, like the CRM lead importer: no queue, no batch persistence, capped
 * at MAX_TASKS / MAX_ITEMS so one request stays a request.
 */
class TaskImportService
{
    /** Hard ceilings — this runs inside one HTTP request. */
    public const MAX_TASKS = 200;

    public const MAX_ITEMS = 1000;

    /**
     * A member's ceiling is deliberately much lower than a manager's.
     *
     * This is one person's own plan, not a project's, and every row lands in the
     * PM's review queue — an unbounded member import is a way to make a reviewer's
     * queue unusable, and to commit a quarter of your own capacity by accident.
     */
    public const MAX_MEMBER_TASKS = 25;

    public function __construct(
        private readonly TaskService $tasks,
        private readonly SubtaskService $subtasks,
        private readonly WorkloadService $workload,
    ) {}

    /**
     * Does this user assign work, or only propose their own?
     *
     * Derived from the permission rather than passed in, so preview, commit and the
     * brief can't disagree about which set of rules a request is under.
     */
    public function isProposer(User $user): bool
    {
        return ! $user->can('projects.tasks.create');
    }

    /**
     * Parse and dry-run an uploaded plan. Writes nothing.
     *
     * @return array<string, mixed>
     */
    public function preview(Project $project, string $json, User $user): array
    {
        $asProposal = $this->isProposer($user);
        $rows = $this->decode($json, $asProposal);
        $maps = $this->resolutionMaps($project);
        $maps['as_proposal'] = $asProposal;
        $maps['author'] = $user;

        /** @var array<string, array<string, mixed>> $unresolved keyed "field|raw" */
        $unresolved = [];
        $tasks = [];
        $subtaskCount = 0;

        foreach ($rows as $index => $raw) {
            $ref = 't'.($index + 1);
            $task = $this->buildTask($raw, $ref, $maps, $unresolved);
            $subtaskCount += count($task['subtasks']);
            $tasks[] = $task;
        }

        // A row is ready only when nothing is wrong with it AND nothing is still
        // unresolved. Unresolved fields carry no validator message by design (they
        // are reported once, in aggregate, not per row), so counting on `errors`
        // alone would call a task with an invented status "ready" when it has no
        // status to be created with.
        $ready = 0;
        $needsAttention = 0;
        foreach ($tasks as $task) {
            foreach (array_merge([$task], $task['subtasks']) as $item) {
                if ($item['errors'] === [] && $item['unresolved_fields'] === []) {
                    $ready++;
                } else {
                    $needsAttention++;
                }
            }
        }

        return [
            'project_id' => (string) $project->uuid,
            'mode' => $asProposal ? 'proposal' : 'direct',
            'summary' => [
                'tasks' => count($tasks),
                'subtasks' => $subtaskCount,
                'ready' => $ready,
                'needs_attention' => $needsAttention,
            ],
            'options' => $this->options($maps),
            'unresolved' => array_values($unresolved),
            'tasks' => $tasks,
            // Only a proposer is committing their own capacity, so only they get the
            // projection. A manager assigning across the team reads the Workload
            // Analysis page instead.
            'workload' => $asProposal ? $this->workloadProjection($user, $tasks) : null,
        ];
    }

    /**
     * What this import would do to the author's own workload.
     *
     * Advisory: it warns, never blocks. Note `current` counts only APPROVED work —
     * a pending proposal is not work yet (WorkloadService::effortFor) — so this is
     * "what your load becomes if your PM approves all of it", and is worded that
     * way in the UI.
     *
     * `adds` mirrors WorkloadService's own rule exactly: a task with sub-tasks
     * contributes nothing and only its sub-tasks count, so the two numbers can't
     * disagree once the work goes live.
     *
     * @param  list<array<string, mixed>>  $tasks
     * @return array<string, mixed>
     */
    private function workloadProjection(User $user, array $tasks): array
    {
        $adds = 0;
        foreach ($tasks as $task) {
            if ($task['subtasks'] !== []) {
                foreach ($task['subtasks'] as $subtask) {
                    $adds += (int) ($subtask['effort_score'] ?? 0);
                }

                continue;
            }
            $adds += (int) ($task['effort_score'] ?? 0);
        }

        $current = $this->workload->effortFor((int) $user->id);
        $projected = $current + $adds;

        return [
            'current' => $current,
            'adds' => $adds,
            'projected' => $projected,
            'level' => $this->workload->level($current),
            'projected_level' => $this->workload->level($projected),
            'thresholds' => $this->workload->thresholds(),
        ];
    }

    /**
     * Create the plan. One transaction: a failure anywhere leaves nothing behind.
     *
     * Each row goes through TaskService/SubtaskService rather than a mass insert,
     * so uuid generation, the task.created / subtask.created activity log,
     * assigned_by_id, status and group scoping, and assignee notifications all
     * behave exactly as they do for a hand-created task.
     *
     * With `$dryRun` it validates and returns instead of writing. That is what
     * powers "Re-validate" after the PM edits the preview: the corrected rows are
     * judged by these exact rules rather than a copy of them reimplemented in the
     * client, which is the only way the two can't drift.
     *
     * @param  list<array<string, mixed>>  $rows  the (possibly corrected) plan from the client
     * @return array{created_tasks: int, created_subtasks: int, errors: array<string, list<string>>}
     */
    public function commit(Project $project, array $rows, User $user, bool $dryRun = false): array
    {
        if ($rows === []) {
            throw ValidationException::withMessages(['tasks' => 'There is nothing to import.']);
        }

        $asProposal = $this->isProposer($user);

        $this->assertWithinCaps($rows, $asProposal);

        $maps = $this->resolutionMaps($project);
        $maps['as_proposal'] = $asProposal;
        $maps['author'] = $user;
        $prepared = [];
        $errors = [];

        // Validate the whole plan before writing any of it, so a bad row 40 never
        // leaves rows 1-39 created.
        foreach ($rows as $index => $row) {
            $ref = is_string($row['ref'] ?? null) && $row['ref'] !== ''
                ? $row['ref']
                : 't'.($index + 1);

            [$task, $rowErrors] = $this->prepareTaskForCommit($row, $ref, $maps);

            foreach ($rowErrors as $errorRef => $messages) {
                if ($messages !== []) {
                    $errors[$errorRef] = $messages;
                }
            }

            $prepared[] = $task;
        }

        if ($dryRun) {
            return ['created_tasks' => 0, 'created_subtasks' => 0, 'errors' => $errors];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $createdTasks = 0;
        $createdSubtasks = 0;

        DB::transaction(function () use ($project, $prepared, $user, &$createdTasks, &$createdSubtasks): void {
            foreach ($prepared as $row) {
                $task = $this->tasks->create($project, $row['task'], $user);
                $createdTasks++;

                foreach ($row['subtasks'] as $subtask) {
                    $this->subtasks->create($task, $subtask, $user);
                    $createdSubtasks++;
                }
            }
        });

        return [
            'created_tasks' => $createdTasks,
            'created_subtasks' => $createdSubtasks,
            'errors' => [],
        ];
    }

    // ---------------------------------------------------------------- parsing

    /**
     * @return list<array<string, mixed>>
     */
    private function decode(string $json, bool $asProposal = false): array
    {
        $trimmed = trim($json);

        if ($trimmed === '') {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        try {
            $decoded = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ValidationException::withMessages([
                'file' => 'This file isn’t valid JSON ('.$e->getMessage().'). If you saved the '
                    .'assistant’s reply, make sure the file contains only what was inside the '
                    .'```json block — no explanation before or after it.',
            ]);
        }

        if (! is_array($decoded)) {
            throw ValidationException::withMessages(['file' => 'The file must contain a JSON object with a "tasks" list.']);
        }

        // Tolerate a bare list — an assistant that emitted the array without the
        // wrapper is otherwise correct, and rejecting that helps nobody.
        $rows = array_is_list($decoded) ? $decoded : ($decoded['tasks'] ?? null);

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw ValidationException::withMessages(['file' => 'The file must contain a "tasks" array.']);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The file contains no tasks.']);
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages(['file' => 'Every entry in "tasks" must be an object.']);
            }
        }

        $this->assertWithinCaps($rows, $asProposal);

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertWithinCaps(array $rows, bool $asProposal = false): void
    {
        $maxTasks = $asProposal ? self::MAX_MEMBER_TASKS : self::MAX_TASKS;

        if (count($rows) > $maxTasks) {
            throw ValidationException::withMessages([
                'file' => 'This plan has '.count($rows).' tasks. You can submit at most '
                    .$maxTasks.' at a time — split it into smaller files and import the '
                    .'most urgent part first.',
            ]);
        }

        $items = count($rows);
        foreach ($rows as $row) {
            $subtasks = $row['subtasks'] ?? [];
            $items += is_array($subtasks) ? count($subtasks) : 0;
        }

        if ($items > self::MAX_ITEMS) {
            throw ValidationException::withMessages([
                'file' => 'This plan has '.$items.' tasks and sub-tasks combined. An import can '
                    .'carry at most '.self::MAX_ITEMS.' — split it into smaller files.',
            ]);
        }
    }

    // ------------------------------------------------------------- resolution

    /**
     * Everything a name can resolve to on this project, built once per request.
     *
     * @return array{
     *   project: Project,
     *   statuses: array<string, ProjectStatus>,
     *   statusList: Collection<int, ProjectStatus>,
     *   groups: array<string, Group>,
     *   groupList: Collection<int, Group>,
     *   members: array<string, list<User>>,
     *   memberList: Collection<int, User>,
     *   default_status: ProjectStatus|null
     * }
     */
    private function resolutionMaps(Project $project): array
    {
        $project->loadMissing(['statuses', 'groups', 'members']);

        $statusList = $project->statuses->sortBy('order')->values();
        $statuses = [];
        foreach ($statusList as $status) {
            // Match on the visible name first, then the stable key ("in_progress"),
            // which survives a rename.
            $statuses[$this->key($status->name)] ??= $status;
            $statuses[$this->key($status->key->value)] ??= $status;
        }

        $groupList = $project->groups->where('archived', false)->sortBy('name')->values();
        $groups = [];
        foreach ($groupList as $group) {
            $groups[$this->key($group->name)] ??= $group;
        }

        $memberList = $project->members->sortBy('name')->values();
        $members = [];
        foreach ($memberList as $member) {
            $members[$this->key($member->name)][] = $member;
        }

        return [
            'project' => $project,
            'statuses' => $statuses,
            'statusList' => $statusList,
            'groups' => $groups,
            'groupList' => $groupList,
            'members' => $members,
            'memberList' => $memberList,
            'default_status' => $statusList->firstWhere('key', StatusKey::TODO) ?? $statusList->first(),
        ];
    }

    /**
     * The pickable values the client renders in its remap and inline editors.
     *
     * @param  array<string, mixed>  $maps
     * @return array<string, mixed>
     */
    private function options(array $maps): array
    {
        return [
            // `key` and `color` ride along so the preview can render a status the
            // same way the board does — the pill is tinted from the status key,
            // or from the status's own colour when it is a custom one. Without
            // them the client can only print the name as plain text, which is
            // exactly how the preview drifted out of step with every other table.
            'statuses' => $maps['statusList']
                ->map(fn (ProjectStatus $s): array => [
                    'value' => (string) $s->uuid,
                    'label' => $s->name,
                    'key' => $s->key->value,
                    'color' => $s->color,
                ])
                ->all(),
            'groups' => $maps['groupList']
                ->map(fn (Group $g): array => ['value' => (string) $g->uuid, 'label' => $g->name])
                ->all(),
            'members' => $maps['memberList']
                ->map(fn (User $u): array => ['value' => $u->id, 'label' => $u->name])
                ->all(),
            'priorities' => array_map(
                static fn (TaskPriority $p): array => ['value' => $p->value, 'label' => $p->label()],
                TaskPriority::cases(),
            ),
        ];
    }

    /** Normalize a human-entered value for lookup: trimmed, collapsed, lowercased. */
    private function key(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return Str::lower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    private function text(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    // --------------------------------------------------------- preview rows

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $maps
     * @param  array<string, array<string, mixed>>  $unresolved
     * @return array<string, mixed>
     */
    private function buildTask(array $raw, string $ref, array $maps, array &$unresolved): array
    {
        $unresolvedFields = [];

        $statusRaw = $this->text($raw['status'] ?? null);
        $status = $this->resolveStatus($statusRaw, $ref, $maps, $unresolved, $unresolvedFields);

        $priorityRaw = $this->text($raw['priority'] ?? null);
        $priority = $this->resolvePriority($priorityRaw, $ref, $unresolved, $unresolvedFields);

        $asProposal = ($maps['as_proposal'] ?? false) === true;

        if ($asProposal) {
            // A proposer can only post work for themselves. `assignee` and `group`
            // are not fields on their brief at all, and TaskController::store makes
            // the same override on the single-create path — a member's assignment
            // target is never trusted from the client. Anything present in the file
            // is ignored rather than rejected; the preview shows every row owned by
            // them, so nothing is silently surprising.
            $groupRaw = null;
            $assigneeRaw = null;
            $group = null;
            $assignee = $maps['author'];
            $conflict = false;
            $assignmentType = AssignmentType::INDIVIDUAL;
        } else {
            $groupRaw = $this->text($raw['group'] ?? null);
            $assigneeRaw = $this->text($raw['assignee'] ?? null);

            $group = null;
            $assignee = null;
            $conflict = $groupRaw !== null && $assigneeRaw !== null;

            if ($groupRaw !== null) {
                $group = $this->resolveGroup($groupRaw, $ref, $maps, $unresolved, $unresolvedFields);
            }
            if ($assigneeRaw !== null) {
                $assignee = $this->resolveMember($assigneeRaw, $ref, $maps, $unresolved, $unresolvedFields);
            }

            // Exclusivity is a real either/or, so we don't silently pick a winner —
            // the row is reported and the PM resolves it with one control.
            $assignmentType = $groupRaw !== null && ! $conflict
                ? AssignmentType::GROUP
                : AssignmentType::INDIVIDUAL;
        }

        $effort = $this->effort($raw['effort_score'] ?? null);

        // Required on a proposer's tasks: it is their own capacity being committed,
        // and the workload projection is meaningless without it. Optional for a
        // manager, matching StoreTaskRequest.
        if ($asProposal && $effort === null) {
            $this->flag($unresolved, 'effort_score', null, $ref, [
                'kind' => 'number',
                'label' => 'Effort score is missing',
                'candidates' => [],
            ]);
            $unresolvedFields[] = 'effort_score';
        }

        $row = [
            'ref' => $ref,
            'kind' => 'task',
            'title' => $this->text($raw['title'] ?? null),
            'description' => $this->text($raw['description'] ?? null),
            'status_id' => $status !== null ? (string) $status->uuid : null,
            'status_raw' => $statusRaw,
            'priority' => $priority?->value,
            'priority_raw' => $priorityRaw,
            'assignment_type' => $assignmentType->value,
            'assignee_id' => $assignee?->id,
            'assignee_raw' => $assigneeRaw,
            'group_id' => $group !== null ? (string) $group->uuid : null,
            'group_raw' => $groupRaw,
            'start_date' => $this->date($raw['start_date'] ?? null),
            'deadline' => $this->date($raw['deadline'] ?? null),
            'effort_score' => $effort,
        ];

        $errors = $this->validateTask($row, $status, $group, $assignee, $unresolvedFields, $asProposal);

        if ($conflict) {
            $errors[] = 'This task names both an assignee ("'.$assigneeRaw.'") and a group ("'
                .$groupRaw.'"). Pick one.';
        }

        $subtaskRows = $raw['subtasks'] ?? [];
        $subtasks = [];
        if (is_array($subtaskRows)) {
            $position = 0;
            foreach ($subtaskRows as $subRaw) {
                if (! is_array($subRaw)) {
                    continue;
                }
                $position++;
                $subtasks[] = $this->buildSubtask(
                    $subRaw,
                    $ref.'s'.$position,
                    $maps,
                    $unresolved,
                    $row['deadline'],
                );
            }
        }

        $row['errors'] = $errors;
        // Which fields the client must fill before this row can be created —
        // either from the aggregated remap bar or the row's own editor.
        $row['unresolved_fields'] = array_values(array_unique($unresolvedFields));
        $row['subtasks'] = $subtasks;

        return $row;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $maps
     * @param  array<string, array<string, mixed>>  $unresolved
     * @return array<string, mixed>
     */
    private function buildSubtask(array $raw, string $ref, array $maps, array &$unresolved, ?string $parentDeadline): array
    {
        $unresolvedFields = [];

        $statusRaw = $this->text($raw['status'] ?? null);
        $status = $this->resolveStatus($statusRaw, $ref, $maps, $unresolved, $unresolvedFields);

        $priorityRaw = $this->text($raw['priority'] ?? null);
        $priority = $this->resolvePriority($priorityRaw, $ref, $unresolved, $unresolvedFields);

        $asProposal = ($maps['as_proposal'] ?? false) === true;

        if ($asProposal) {
            $assigneeRaw = null;
            $assignee = $maps['author'];
        } else {
            $assigneeRaw = $this->text($raw['assignee'] ?? null);
            $assignee = $assigneeRaw !== null
                ? $this->resolveMember($assigneeRaw, $ref, $maps, $unresolved, $unresolvedFields)
                : null;
        }

        $effort = $this->effort($raw['effort_score'] ?? null);

        // Required on a sub-task, and the single most common thing an assistant
        // drops. Aggregated so the PM sets all of them at once.
        if ($effort === null) {
            $this->flag($unresolved, 'effort_score', null, $ref, [
                'kind' => 'number',
                'label' => 'Sub-task effort score is missing',
                'candidates' => [],
            ]);
            $unresolvedFields[] = 'effort_score';
        }

        $row = [
            'ref' => $ref,
            'kind' => 'subtask',
            'name' => $this->text($raw['name'] ?? $raw['title'] ?? null),
            'description' => $this->text($raw['description'] ?? null),
            'status_id' => $status !== null ? (string) $status->uuid : null,
            'status_raw' => $statusRaw,
            'priority' => $priority?->value,
            'priority_raw' => $priorityRaw,
            'assignee_id' => $assignee?->id,
            'assignee_raw' => $assigneeRaw,
            'deadline' => $this->date($raw['deadline'] ?? null),
            'effort_score' => $effort,
        ];

        $row['errors'] = $this->validateSubtask($row, $status, $assignee, $parentDeadline, $unresolvedFields);
        $row['unresolved_fields'] = array_values(array_unique($unresolvedFields));

        return $row;
    }

    // ------------------------------------------------------------- resolvers

    /**
     * @param  array<string, mixed>  $maps
     * @param  array<string, array<string, mixed>>  $unresolved
     * @param  list<string>  $unresolvedFields
     */
    private function resolveStatus(?string $raw, string $ref, array $maps, array &$unresolved, array &$unresolvedFields): ?ProjectStatus
    {
        if ($raw === null) {
            // A missing status is not a mistake — it lands in the project's first
            // column, exactly as the brief promises.
            return $maps['default_status'];
        }

        $match = $maps['statuses'][$this->key($raw)] ?? null;
        if ($match !== null) {
            return $match;
        }

        $this->flag($unresolved, 'status', $raw, $ref, [
            'kind' => 'select',
            'label' => '“'.$raw.'” is not a status in this project',
            'candidates' => $maps['statusList']
                ->map(fn (ProjectStatus $s): array => ['value' => (string) $s->uuid, 'label' => $s->name])
                ->all(),
        ]);
        $unresolvedFields[] = 'status_id';

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $unresolved
     * @param  list<string>  $unresolvedFields
     */
    private function resolvePriority(?string $raw, string $ref, array &$unresolved, array &$unresolvedFields): ?TaskPriority
    {
        if ($raw !== null) {
            // Case-insensitive on purpose: "high" is the assistant's most common
            // near-miss and carries no ambiguity, so it is translated, not reported.
            foreach (TaskPriority::cases() as $case) {
                if ($this->key($case->value) === $this->key($raw)) {
                    return $case;
                }
            }
        }

        $this->flag($unresolved, 'priority', $raw, $ref, [
            'kind' => 'select',
            'label' => $raw === null
                ? 'Priority is missing'
                : '“'.$raw.'” is not a priority',
            'candidates' => array_map(
                static fn (TaskPriority $p): array => ['value' => $p->value, 'label' => $p->label()],
                TaskPriority::cases(),
            ),
        ]);
        $unresolvedFields[] = 'priority';

        return null;
    }

    /**
     * @param  array<string, mixed>  $maps
     * @param  array<string, array<string, mixed>>  $unresolved
     * @param  list<string>  $unresolvedFields
     */
    private function resolveGroup(string $raw, string $ref, array $maps, array &$unresolved, array &$unresolvedFields): ?Group
    {
        $match = $maps['groups'][$this->key($raw)] ?? null;
        if ($match !== null) {
            return $match;
        }

        $this->flag($unresolved, 'group', $raw, $ref, [
            'kind' => 'select',
            'label' => '“'.$raw.'” is not a group on this project',
            'candidates' => $maps['groupList']
                ->map(fn (Group $g): array => ['value' => (string) $g->uuid, 'label' => $g->name])
                ->all(),
        ]);
        $unresolvedFields[] = 'group_id';

        return null;
    }

    /**
     * Resolve a person's name against the PROJECT ROSTER only.
     *
     * This is deliberately narrower than StoreTaskRequest, which accepts any
     * `exists:users,id`: an import can never put work on somebody who isn't on
     * the project, and the brief only ever offers roster names.
     *
     * @param  array<string, mixed>  $maps
     * @param  array<string, array<string, mixed>>  $unresolved
     * @param  list<string>  $unresolvedFields
     */
    private function resolveMember(string $raw, string $ref, array $maps, array &$unresolved, array &$unresolvedFields): ?User
    {
        $matches = $maps['members'][$this->key($raw)] ?? [];

        if (count($matches) === 1) {
            return $matches[0];
        }

        $ambiguous = count($matches) > 1;

        $this->flag($unresolved, 'assignee', $raw, $ref, [
            'kind' => 'select',
            'label' => $ambiguous
                ? '“'.$raw.'” matches more than one member'
                : '“'.$raw.'” isn’t a member of this project',
            // An ambiguous name narrows to the people it actually matched; an
            // unknown one offers the whole roster.
            'candidates' => $ambiguous
                ? array_map(static fn (User $u): array => ['value' => $u->id, 'label' => $u->name], $matches)
                : $maps['memberList']->map(fn (User $u): array => ['value' => $u->id, 'label' => $u->name])->all(),
        ]);
        $unresolvedFields[] = 'assignee_id';

        return null;
    }

    /**
     * Record one unresolved value, merging repeats so a mistake an assistant made
     * on 14 rows is one thing for the PM to fix, not 14.
     *
     * @param  array<string, array<string, mixed>>  $unresolved
     * @param  array<string, mixed>  $meta
     */
    private function flag(array &$unresolved, string $field, ?string $raw, string $ref, array $meta): void
    {
        $key = $field.'|'.($raw ?? '');

        if (! isset($unresolved[$key])) {
            $unresolved[$key] = array_merge([
                'field' => $field,
                'raw' => $raw,
                'refs' => [],
                'count' => 0,
            ], $meta);
        }

        $unresolved[$key]['refs'][] = $ref;
        $unresolved[$key]['count']++;
    }

    // ------------------------------------------------------------ validation

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $unresolvedFields
     * @return list<string>
     */
    private function validateTask(
        array $row,
        ?ProjectStatus $status,
        ?Group $group,
        ?User $assignee,
        array $unresolvedFields,
        bool $requireEffort = false,
    ): array {
        // Validate against the same rules a hand-created task goes through, using
        // internal ids (what StoreTaskRequest sees after prepareForValidation).
        $data = [
            'title' => $row['title'],
            'description' => $row['description'],
            'start_date' => $row['start_date'],
            'deadline' => $row['deadline'],
            'assignment_type' => $row['assignment_type'],
            'priority' => $row['priority'],
            'status_id' => $status?->id,
            'effort_score' => $row['effort_score'],
        ];

        if ($row['assignment_type'] === AssignmentType::GROUP->value) {
            $data['group_id'] = $group?->id;
        } elseif ($assignee !== null) {
            $data['assignee_id'] = $assignee->id;
        }

        $rules = (new StoreTaskRequest)->rules();

        if ($requireEffort) {
            // A proposer is committing their own capacity, so effort stops being
            // optional — the workload projection they and their reviewer both read
            // is only meaningful if every row carries one.
            $rules['effort_score'] = ['required', 'integer', 'min:0', 'max:10'];
        }

        $validator = Validator::make($data, $rules, (new StoreTaskRequest)->messages());

        $errors = $this->messagesExcept($validator, $unresolvedFields);

        $start = $this->asDate($row['start_date']);
        $deadline = $this->asDate($row['deadline']);
        if ($start !== null && $deadline !== null && $deadline->lessThan($start)) {
            $errors[] = 'The due date must be on or after the start date.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $unresolvedFields
     * @return list<string>
     */
    private function validateSubtask(array $row, ?ProjectStatus $status, ?User $assignee, ?string $parentDeadline, array $unresolvedFields): array
    {
        $data = [
            'name' => $row['name'],
            'description' => $row['description'],
            'assignee_id' => $assignee?->id,
            'status_id' => $status?->id,
            'priority' => $row['priority'],
            'effort_score' => $row['effort_score'],
            'deadline' => $row['deadline'],
        ];

        $rules = (new StoreSubtaskRequest)->rules();
        $validator = Validator::make($data, $rules, (new StoreSubtaskRequest)->messages());

        $errors = $this->messagesExcept($validator, $unresolvedFields);

        // StoreSubtaskRequest enforces this against the route's parent task; here
        // the parent is the row above it in the same file.
        $deadline = $this->asDate($row['deadline']);
        $parent = $this->asDate($parentDeadline);
        if ($deadline !== null && $parent !== null && $deadline->greaterThan($parent)) {
            $errors[] = 'A sub-task’s due date can’t be after its task’s due date ('.$parentDeadline.').';
        }

        return $errors;
    }

    /**
     * Validator messages, minus the fields already reported as unresolved.
     *
     * Without this a status the assistant invented would be reported twice: once
     * as the thing the PM can actually fix, and again as an unhelpful
     * "status id field is required".
     *
     * @param  list<string>  $unresolvedFields
     * @return list<string>
     */
    private function messagesExcept(\Illuminate\Contracts\Validation\Validator $validator, array $unresolvedFields): array
    {
        $messages = [];

        foreach ($validator->errors()->messages() as $attribute => $attributeMessages) {
            if (in_array($attribute, $unresolvedFields, true)) {
                continue;
            }
            foreach ($attributeMessages as $message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    // -------------------------------------------------------------- commit

    /**
     * Turn one client row into the payloads TaskService/SubtaskService expect,
     * re-validating on the way. Public ids in, internal ids out.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $maps
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>} payload, errors keyed by row ref
     */
    private function prepareTaskForCommit(array $row, string $ref, array $maps): array
    {
        $project = $maps['project'];
        $asProposal = ($maps['as_proposal'] ?? false) === true;

        $status = $this->statusFromId($row['status_id'] ?? null, $project);
        $priority = $this->text($row['priority'] ?? null);

        if ($asProposal) {
            // Re-forced on commit, not just on preview: the client's copy of the plan
            // is editable, so the only safe place to decide who a proposer's work
            // belongs to is here.
            $assignmentType = AssignmentType::INDIVIDUAL;
            $group = null;
            $assignee = $maps['author'];
        } else {
            $assignmentType = ($row['assignment_type'] ?? null) === AssignmentType::GROUP->value
                ? AssignmentType::GROUP
                : AssignmentType::INDIVIDUAL;

            $group = $assignmentType === AssignmentType::GROUP
                ? $this->groupFromId($row['group_id'] ?? null, $maps)
                : null;

            $assignee = $assignmentType === AssignmentType::INDIVIDUAL
                ? $this->memberFromId($row['assignee_id'] ?? null, $maps)
                : null;
        }

        $normalized = [
            'title' => $this->text($row['title'] ?? null),
            'description' => $this->text($row['description'] ?? null),
            'status_id' => $row['status_id'] ?? null,
            'priority' => $priority,
            'assignment_type' => $assignmentType->value,
            'assignee_id' => $assignee?->id,
            'start_date' => $this->date($row['start_date'] ?? null),
            'deadline' => $this->date($row['deadline'] ?? null),
            'effort_score' => $this->effort($row['effort_score'] ?? null),
        ];

        $errors = $this->validateTask($normalized, $status, $group, $assignee, [], $asProposal);

        if ($assignmentType === AssignmentType::GROUP && $group === null) {
            $errors[] = 'Group-assigned task “'.($normalized['title'] ?? $ref).'” has no valid group.';
        }
        if ($assignmentType === AssignmentType::INDIVIDUAL
            && ($row['assignee_id'] ?? null) !== null
            && $assignee === null) {
            $errors[] = 'Task “'.($normalized['title'] ?? $ref).'” is assigned to someone who is not a member of this project.';
        }

        $payload = [
            'title' => $normalized['title'],
            'description' => $normalized['description'],
            'status_id' => $status?->id,
            'priority' => $priority,
            'assignment_type' => $assignmentType->value,
            'assignee_id' => $assignee?->id,
            'group_id' => $group?->id,
            'start_date' => $normalized['start_date'],
            'deadline' => $normalized['deadline'],
            'effort_score' => $normalized['effort_score'],
            // Stated outright rather than left to the column default, for the same
            // reason TaskController::store states it: the model Task::create()
            // returns would otherwise carry a null review state, which reads as
            // "proposal" everywhere — including SubtaskService, which inherits its
            // parent's approval and would then try to write a null of its own.
            //
            // A manager's import is approved on the spot. A proposer's is PENDING:
            // it stays off every board, workload total and search result until a PM
            // approves it, and its sub-tasks inherit that review through
            // SubtaskService, so the whole task is one item in the approvals queue.
            'approval_status' => $asProposal ? ApprovalStatus::PENDING : ApprovalStatus::APPROVED,
            'submitted_by_id' => $asProposal ? $maps['author']->id : null,
        ];

        $errorsByRef = [$ref => $errors];

        $subtasks = [];
        $rawSubtasks = $row['subtasks'] ?? [];
        if (is_array($rawSubtasks)) {
            $position = 0;
            foreach ($rawSubtasks as $subRow) {
                if (! is_array($subRow)) {
                    continue;
                }
                $position++;
                $subRef = is_string($subRow['ref'] ?? null) && $subRow['ref'] !== ''
                    ? $subRow['ref']
                    : $ref.'s'.$position;

                [$subPayload, $subErrors] = $this->prepareSubtaskForCommit(
                    $subRow,
                    $subRef,
                    $maps,
                    $normalized['deadline'],
                );
                $errorsByRef[$subRef] = $subErrors;
                $subtasks[] = $subPayload;
            }
        }

        return [['task' => $payload, 'subtasks' => $subtasks], $errorsByRef];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $maps
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function prepareSubtaskForCommit(array $row, string $ref, array $maps, ?string $parentDeadline): array
    {
        $asProposal = ($maps['as_proposal'] ?? false) === true;

        $status = $this->statusFromId($row['status_id'] ?? null, $maps['project']);
        // A proposer's sub-tasks are theirs too. SubtaskService then inherits the
        // parent's PENDING review and its submitted_by_id, so the task and its
        // sub-tasks reach the approvals queue as one reviewable bundle.
        $assignee = $asProposal
            ? $maps['author']
            : $this->memberFromId($row['assignee_id'] ?? null, $maps);

        $normalized = [
            'name' => $this->text($row['name'] ?? null),
            'description' => $this->text($row['description'] ?? null),
            'priority' => $this->text($row['priority'] ?? null),
            'deadline' => $this->date($row['deadline'] ?? null),
            'effort_score' => $this->effort($row['effort_score'] ?? null),
        ];

        $errors = $this->validateSubtask($normalized, $status, $assignee, $parentDeadline, []);

        if (($row['assignee_id'] ?? null) !== null && $assignee === null) {
            $errors[] = 'Sub-task “'.($normalized['name'] ?? $ref).'” is assigned to someone who is not a member of this project.';
        }

        return [[
            'name' => $normalized['name'],
            'description' => $normalized['description'],
            'assignee_id' => $assignee?->id,
            'status_id' => $status?->id,
            'priority' => $normalized['priority'],
            'effort_score' => $normalized['effort_score'],
            'deadline' => $normalized['deadline'],
        ], $errors];
    }

    private function statusFromId(mixed $value, Project $project): ?ProjectStatus
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $id = PublicId::toInternalId(ProjectStatus::class, $value);

        // Scoped to the project so a status uuid from another board can't ride in
        // on a hand-edited payload. TaskService asserts this too — this is the
        // earlier, friendlier failure.
        return $project->statuses->firstWhere('id', $id);
    }

    /**
     * @param  array<string, mixed>  $maps
     */
    private function groupFromId(mixed $value, array $maps): ?Group
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $id = PublicId::toInternalId(Group::class, $value);

        return $maps['groupList']->firstWhere('id', $id);
    }

    /**
     * @param  array<string, mixed>  $maps
     */
    private function memberFromId(mixed $value, array $maps): ?User
    {
        if (! is_numeric($value)) {
            return null;
        }

        return $maps['memberList']->firstWhere('id', (int) $value);
    }

    // --------------------------------------------------------------- casting

    private function date(mixed $value): ?string
    {
        $text = $this->text($value);
        if ($text === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($text)->toDateString();
        } catch (\Throwable) {
            // Kept as-is so the row reports "is not a valid date" against what the
            // assistant actually wrote, rather than silently dropping it.
            return $text;
        }
    }

    private function asDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function effort(mixed $value): ?int
    {
        if (is_bool($value) || $value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
