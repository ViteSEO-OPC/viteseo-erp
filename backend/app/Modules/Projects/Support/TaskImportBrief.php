<?php

declare(strict_types=1);

namespace App\Modules\Projects\Support;

use App\Modules\Projects\Enums\StatusKey;
use App\Modules\Projects\Enums\TaskPriority;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\TaskImportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Builds the Markdown brief a user hands to an LLM.
 *
 * The brief is the whole contract: it is the only thing the assistant sees, so
 * every constraint TaskImportService enforces has to be stated here in words the
 * model will follow. Anything omitted here comes back as a row the user has to
 * fix by hand in the preview.
 *
 * TWO BRIEFS, because the two roles can post fundamentally different work:
 *
 *  - A **manager** assigns. Their brief carries the project's groups and member
 *    display names (never email addresses — the brief is pasted into a
 *    third-party assistant, so the roster's contact details must not travel with
 *    it) and lets a task name an assignee OR a group, or neither.
 *  - A **member** proposes. They can only post work for themselves, so their
 *    brief has no `assignee` and no `group` field at all — which also means
 *    nothing about the roster leaves the org on that path. Everything they
 *    import is self-assigned and pending a PM's review, and effort is required
 *    on every row because it is their own capacity being committed.
 */
final class TaskImportBrief
{
    /**
     * Render the brief for one project.
     *
     * @param  bool  $forMember  true for the propose-only member brief
     */
    public static function for(Project $project, bool $forMember = false): string
    {
        $project->loadMissing(['statuses', 'groups', 'members']);

        $statuses = $project->statuses->sortBy('order')->pluck('name')->all();
        $priorities = array_column(TaskPriority::cases(), 'value');

        // The status a row falls back to when the assistant omits one. Matches
        // TaskImportService::resolveStatus().
        $default = $project->statuses->firstWhere('key', StatusKey::TODO)
            ?? $project->statuses->sortBy('order')->first();

        // Stated in the user's own timezone: the assistant has no reliable sense
        // of the current date, and "no dates in the past" is unusable without it.
        // Manila runs ahead of the app timezone (UTC), so a date at or after the
        // Manila date always clears the UTC `after_or_equal:today` check.
        $today = CarbonImmutable::now(config('app.timezone_local', 'Asia/Manila'));

        if ($forMember) {
            return implode("\n", [
                self::memberHeading($project, $today),
                self::outputRules(true),
                self::memberSchema(),
                self::memberAllowedValues($statuses, $priorities, $default?->name),
                self::memberRules($today, $default?->name),
                self::memberExample($statuses, $today),
                self::memberFooter(),
            ]);
        }

        $groups = $project->groups->where('archived', false)
            ->pluck('name')->sort()->values()->all();
        $members = $project->members->pluck('name')->sort()->values()->all();

        return implode("\n", [
            self::heading($project, $today),
            self::outputRules(),
            self::schema(),
            self::allowedValues($statuses, $groups, $members, $priorities, $default?->name),
            self::rules($today, $default?->name),
            self::example($statuses, $groups, $members, $today),
            self::footer(),
        ]);
    }

    /** Suggested download filename, e.g. `sprintly-import-website-revamp.md`. */
    public static function filename(Project $project, bool $forMember = false): string
    {
        $slug = Str::slug($project->name);
        $suffix = $forMember ? '-my-tasks' : '';

        return 'sprintly-import-'.($slug !== '' ? $slug : 'project').$suffix.'.md';
    }

    // ------------------------------------------------------------- manager

    private static function heading(Project $project, CarbonImmutable $today): string
    {
        return <<<MD
        # Sprintly task import brief — {$project->name}

        You are helping a project manager turn a project plan into tasks in Sprintly.

        Read this whole brief, then work with the PM on the plan. When they are happy
        with it, produce the JSON file described below. They will save it and upload it
        to Sprintly, which creates every task and sub-task in one go.

        Today's date is **{$today->toDateString()}**. Plan from this date forward.


        MD;
    }

    private static function outputRules(bool $forMember = false): string
    {
        // A proposer's brief lists no groups and no people, so telling it not to
        // invent them would point at sections that aren't there.
        $inventionRule = $forMember
            ? '- Never invent a status that is not listed under "Allowed values" below.'
            : '- Never invent a status, group or person that is not listed under'."\n".
              '  "Allowed values" below.';

        return <<<MD
        ## Output rules

        - Return **exactly one** fenced ```json code block, and nothing else after it.
        - No commentary, no explanation, and no notes inside or after the block.
        - The block must be valid JSON. No trailing commas, no comments, no `...`.
        {$inventionRule}
          If the plan needs one that doesn't exist, use the closest one that does and
          say so before you emit the block.
        - Output every task the plan calls for. Do not truncate, summarise, or write
          "and so on" — the file is the plan.


        MD;
    }

    private static function schema(): string
    {
        return <<<'MD'
        ## Schema

        ```json
        {
          "tasks": [
            {
              "title": "string, required, max 255 characters",
              "description": "string, optional",
              "status": "string, optional — see Allowed values",
              "priority": "Low | Medium | High, required",
              "assignee": "string, optional — a member name from Allowed values",
              "group": "string, optional — a group name from Allowed values",
              "start_date": "YYYY-MM-DD, optional",
              "deadline": "YYYY-MM-DD, optional",
              "effort_score": "whole number 0-10, optional",
              "subtasks": [
                {
                  "name": "string, required, max 255 characters",
                  "description": "string, optional",
                  "status": "string, optional",
                  "priority": "Low | Medium | High, required",
                  "assignee": "string, optional — a member name",
                  "effort_score": "whole number 0-10, REQUIRED",
                  "deadline": "YYYY-MM-DD, optional"
                }
              ]
            }
          ]
        }
        ```

        `subtasks` may be an empty array or omitted entirely. Sub-tasks nest one level
        only — a sub-task cannot itself contain sub-tasks.


        MD;
    }

    /**
     * @param  list<string>  $statuses
     * @param  list<string>  $groups
     * @param  list<string>  $members
     * @param  list<string>  $priorities
     */
    private static function allowedValues(
        array $statuses,
        array $groups,
        array $members,
        array $priorities,
        ?string $defaultStatus,
    ): string {
        $statusList = self::bullets($statuses, 'This project has no statuses yet — omit the field.');
        $groupList = self::bullets($groups, 'This project has no groups — every task must be individual or unassigned.');
        $memberList = self::bullets($members, 'This project has no members yet — leave every task unassigned.');
        $priorityList = self::bullets($priorities);

        $defaultNote = $defaultStatus !== null
            ? "Omit `status` and the task lands in **{$defaultStatus}**."
            : 'Omit `status` and the task lands in the project’s first column.';

        return <<<MD
        ## Allowed values for THIS project

        Copy these strings **exactly** — spelling, spacing and capitalisation all matter.

        ### status
        {$statusList}

        {$defaultNote}

        ### priority
        {$priorityList}

        ### group
        {$groupList}

        ### assignee
        {$memberList}

        Use a person's name exactly as written above. If the plan mentions someone who
        is not on this list, they are not on this project — leave that work unassigned
        and say so.


        MD;
    }

    private static function rules(CarbonImmutable $today, ?string $defaultStatus): string
    {
        $todayStr = $today->toDateString();
        $statusRule = $defaultStatus !== null
            ? "Omitting it puts the task in **{$defaultStatus}**."
            : 'Omitting it puts the task in the project’s first column.';
        $maxTasks = TaskImportService::MAX_TASKS;

        return <<<MD
        ## Rules Sprintly enforces

        A file that breaks one of these is not rejected outright — the PM sees the bad
        rows in a preview and fixes them by hand. Every one you get right is one they
        don't have to correct.

        1. **Assignment is either/or.** A task may name an `assignee` **or** a `group`,
           never both. Omit both to leave it unassigned — unassigned tasks go to the
           open task pool for anyone with capacity to claim.
        2. **Sub-tasks are assigned to people, never to groups.** A sub-task has no
           `group` field.
        3. **`effort_score` is required on every sub-task**, and is a whole number from
           0 to 10. On a task it is optional (also 0–10). It represents relative effort,
           not hours — keep it consistent across the plan so the workload chart means
           something.
        4. **`priority` is case-sensitive**: `Low`, `Medium`, `High`. Not `high`, not `HIGH`.
        5. **Dates are `YYYY-MM-DD`** and must be **{$todayStr} or later**. No past dates.
        6. **A task's `deadline` must be on or after its `start_date`.**
        7. **A sub-task's `deadline` must be on or before its task's `deadline`.** The
           work inside a task cannot finish after the task itself is due.
        8. **`status`** must be one of the listed names. {$statusRule}
        9. **At most {$maxTasks} tasks** in one file.


        MD;
    }

    /**
     * @param  list<string>  $statuses
     * @param  list<string>  $groups
     * @param  list<string>  $members
     */
    private static function example(array $statuses, array $groups, array $members, CarbonImmutable $today): string
    {
        // Built from this project's real values so the example is directly copyable
        // rather than a generic sample the assistant has to translate. Assembled as
        // a PHP structure and encoded, so the sample is guaranteed to be the valid
        // JSON we're asking the assistant to produce.
        $status = $statuses[0] ?? 'To Do';
        $group = $groups[0] ?? null;
        $member = $members[0] ?? null;

        $start = $today->addDays(1)->toDateString();
        $mid = $today->addDays(10)->toDateString();
        $end = $today->addDays(21)->toDateString();

        $individual = [
            'title' => 'Audit the current information architecture',
            'description' => 'Map every existing page and note what to keep, merge or cut.',
            'status' => $status,
            'priority' => 'High',
            'start_date' => $start,
            'deadline' => $mid,
            'effort_score' => 5,
            'subtasks' => [
                array_filter([
                    'name' => 'Export the current sitemap',
                    'status' => $status,
                    'priority' => 'Medium',
                    'assignee' => $member,
                    'effort_score' => 2,
                    'deadline' => $start,
                ], static fn ($v): bool => $v !== null),
                [
                    'name' => 'Tag each page keep / merge / cut',
                    'status' => $status,
                    'priority' => 'High',
                    'effort_score' => 3,
                    'deadline' => $mid,
                ],
            ],
        ];

        if ($member !== null) {
            // Keep `assignee` next to the other task fields rather than after subtasks.
            $individual = array_merge(
                array_slice($individual, 0, 4, true),
                ['assignee' => $member],
                array_slice($individual, 4, null, true),
            );
        }

        $tasks = [$individual];

        if ($group !== null) {
            $tasks[] = [
                'title' => 'Rebuild the checkout flow',
                'description' => 'Owned by the group — whoever picks it up coordinates inside the pod.',
                'status' => $status,
                'priority' => 'Medium',
                'group' => $group,
                'start_date' => $mid,
                'deadline' => $end,
                'effort_score' => 8,
                'subtasks' => [],
            ];
        }

        $tasks[] = [
            'title' => 'Write the migration runbook',
            'description' => 'Left unassigned on purpose — goes to the open task pool.',
            'status' => $status,
            'priority' => 'Low',
            'deadline' => $end,
            'effort_score' => 3,
            'subtasks' => [],
        ];

        $shape = $group !== null
            ? 'Three tasks: one assigned to a person with sub-tasks, one owned by a group, and one left open for the pool.'
            : 'Two tasks: one assigned to a person with sub-tasks, and one left open for the pool.';

        return "## Worked example\n\n{$shape}\n\n```json\n".self::json($tasks)."\n```\n\n\n";
    }

    private static function footer(): string
    {
        return <<<'MD'
        ## Before you emit the file

        Check each of these against your own output:

        - [ ] Every `status`, `group` and `assignee` string appears verbatim in "Allowed values".
        - [ ] No task has both an `assignee` and a `group`.
        - [ ] Every sub-task has an `effort_score` between 0 and 10.
        - [ ] Every date is `YYYY-MM-DD`, today or later.
        - [ ] No sub-task deadline falls after its task's deadline.
        - [ ] `priority` is exactly `Low`, `Medium` or `High` on every task and sub-task.
        - [ ] The JSON parses, and it is the only thing in the code block.

        MD;
    }

    // -------------------------------------------------------------- member

    private static function memberHeading(Project $project, CarbonImmutable $today): string
    {
        return <<<MD
        # Sprintly task import brief — {$project->name} (my work)

        You are helping a team member plan their own work on this project and turn it
        into tasks in Sprintly.

        Read this whole brief, then work the plan out with them. When they are happy
        with it, produce the JSON file described below. They will save it and upload it
        to Sprintly.

        **Two things to understand before you plan:**

        1. **Everything in this file is their own work.** A member can only post tasks
           for themselves — there is no way to assign work to a colleague or to a group
           here. Do not add an `assignee` or a `group` field; Sprintly ignores them.
        2. **Nothing goes live immediately.** Every task is submitted to their project
           manager for review and appears on the board only once the PM approves it. A
           task and its sub-tasks are reviewed together as one item, so grouping related
           work under one task is genuinely helpful to the reviewer.

        Today's date is **{$today->toDateString()}**. Plan from this date forward.


        MD;
    }

    private static function memberSchema(): string
    {
        return <<<'MD'
        ## Schema

        ```json
        {
          "tasks": [
            {
              "title": "string, required, max 255 characters",
              "description": "string, optional",
              "status": "string, optional — see Allowed values",
              "priority": "Low | Medium | High, required",
              "start_date": "YYYY-MM-DD, optional",
              "deadline": "YYYY-MM-DD, optional",
              "effort_score": "whole number 0-10, REQUIRED",
              "subtasks": [
                {
                  "name": "string, required, max 255 characters",
                  "description": "string, optional",
                  "status": "string, optional",
                  "priority": "Low | Medium | High, required",
                  "effort_score": "whole number 0-10, REQUIRED",
                  "deadline": "YYYY-MM-DD, optional"
                }
              ]
            }
          ]
        }
        ```

        There is deliberately **no `assignee` and no `group` field** — every task and
        sub-task here belongs to the person importing it.

        `subtasks` may be an empty array or omitted entirely. Sub-tasks nest one level
        only — a sub-task cannot itself contain sub-tasks.


        MD;
    }

    /**
     * @param  list<string>  $statuses
     * @param  list<string>  $priorities
     */
    private static function memberAllowedValues(array $statuses, array $priorities, ?string $defaultStatus): string
    {
        $statusList = self::bullets($statuses, 'This project has no statuses yet — omit the field.');
        $priorityList = self::bullets($priorities);

        $defaultNote = $defaultStatus !== null
            ? "Omit `status` and the task lands in **{$defaultStatus}**."
            : 'Omit `status` and the task lands in the project’s first column.';

        return <<<MD
        ## Allowed values for THIS project

        Copy these strings **exactly** — spelling, spacing and capitalisation all matter.

        ### status
        {$statusList}

        {$defaultNote}

        ### priority
        {$priorityList}


        MD;
    }

    private static function memberRules(CarbonImmutable $today, ?string $defaultStatus): string
    {
        $todayStr = $today->toDateString();
        $statusRule = $defaultStatus !== null
            ? "Omitting it puts the task in **{$defaultStatus}**."
            : 'Omitting it puts the task in the project’s first column.';
        $maxTasks = TaskImportService::MAX_MEMBER_TASKS;

        return <<<MD
        ## Rules Sprintly enforces

        A file that breaks one of these is not rejected outright — they will see the bad
        rows in a preview and fix them by hand. Every one you get right is one they
        don't have to correct.

        1. **`effort_score` is required on every task AND every sub-task**, as a whole
           number from 0 to 10. This is the single most important field here: it is how
           Sprintly works out whether they are taking on too much, and their PM sees
           that total when reviewing. It is relative effort, not hours — keep the scale
           consistent across the whole plan.
        2. **A task with sub-tasks is measured by its sub-tasks.** Sprintly counts the
           sub-tasks' effort and ignores the parent's, so the sub-task scores are the
           ones that have to be right. Give the parent a score anyway.
        3. **No `assignee` and no `group` fields.** Everything is theirs; both fields are
           ignored if present.
        4. **`priority` is case-sensitive**: `Low`, `Medium`, `High`. Not `high`, not `HIGH`.
        5. **Dates are `YYYY-MM-DD`** and must be **{$todayStr} or later**. No past dates.
        6. **A task's `deadline` must be on or after its `start_date`.**
        7. **A sub-task's `deadline` must be on or before its task's `deadline`.**
        8. **`status`** must be one of the listed names. {$statusRule}
        9. **At most {$maxTasks} tasks** in one file. This is a plan for one person's own
           work, not a whole project — if the plan is bigger than that, split it and
           import the most urgent part first.


        MD;
    }

    /**
     * @param  list<string>  $statuses
     */
    private static function memberExample(array $statuses, CarbonImmutable $today): string
    {
        $status = $statuses[0] ?? 'To Do';
        $start = $today->addDays(1)->toDateString();
        $mid = $today->addDays(10)->toDateString();
        $end = $today->addDays(21)->toDateString();

        $tasks = [
            [
                'title' => 'Audit the current information architecture',
                'description' => 'Map every existing page and note what to keep, merge or cut.',
                'status' => $status,
                'priority' => 'High',
                'start_date' => $start,
                'deadline' => $mid,
                'effort_score' => 5,
                'subtasks' => [
                    [
                        'name' => 'Export the current sitemap',
                        'status' => $status,
                        'priority' => 'Medium',
                        'effort_score' => 2,
                        'deadline' => $start,
                    ],
                    [
                        'name' => 'Tag each page keep / merge / cut',
                        'status' => $status,
                        'priority' => 'High',
                        'effort_score' => 3,
                        'deadline' => $mid,
                    ],
                ],
            ],
            [
                'title' => 'Write the migration runbook',
                'description' => 'No sub-tasks, so this task’s own effort score is what counts.',
                'status' => $status,
                'priority' => 'Low',
                'deadline' => $end,
                'effort_score' => 3,
                'subtasks' => [],
            ],
        ];

        return "## Worked example\n\nTwo tasks: one broken into sub-tasks, one standing on its own.\n\n"
            ."```json\n".self::json($tasks)."\n```\n\n\n";
    }

    private static function memberFooter(): string
    {
        return <<<'MD'
        ## Before you emit the file

        Check each of these against your own output:

        - [ ] Every task **and** every sub-task has an `effort_score` between 0 and 10.
        - [ ] No `assignee` or `group` field appears anywhere.
        - [ ] Every `status` string appears verbatim in "Allowed values".
        - [ ] Every date is `YYYY-MM-DD`, today or later.
        - [ ] No sub-task deadline falls after its task's deadline.
        - [ ] `priority` is exactly `Low`, `Medium` or `High` on every task and sub-task.
        - [ ] The JSON parses, and it is the only thing in the code block.

        MD;
    }

    // -------------------------------------------------------------- shared

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    private static function json(array $tasks): string
    {
        return (string) json_encode(
            ['tasks' => $tasks],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @param  list<string>  $values
     */
    private static function bullets(array $values, ?string $emptyNote = null): string
    {
        if ($values === []) {
            return '_'.($emptyNote ?? 'None available.').'_';
        }

        return implode("\n", array_map(static fn (string $v): string => '- `'.$v.'`', $values));
    }
}
