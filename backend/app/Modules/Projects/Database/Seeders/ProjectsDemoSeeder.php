<?php

declare(strict_types=1);

namespace App\Modules\Projects\Database\Seeders;

use App\Models\User;
use App\Modules\Projects\Enums\AssignmentType;
use App\Modules\Projects\Enums\ProjectState;
use App\Modules\Projects\Enums\StatusKey;
use App\Modules\Projects\Enums\TaskPriority;
use App\Modules\Projects\Models\ActivityLog;
use App\Modules\Projects\Models\Comment;
use App\Modules\Projects\Models\Group;
use App\Modules\Projects\Models\Link;
use App\Modules\Projects\Models\Notification;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectStatus;
use App\Modules\Projects\Models\Subtask;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Demo PMS data. Reuses the platform users created by the HR EmployeeDemoSeeder
 * (so PMS shares the same people/logins), grants them PMS roles, and builds a
 * realistic workspace: projects with the 3 protected default statuses + a custom
 * one, project members, groups, individual/group tasks, subtasks, comments,
 * links, activity logs, and notifications.
 *
 * Must run AFTER EmployeeDemoSeeder (users) and RolesAndPermissionsSeeder (roles).
 */
class ProjectsDemoSeeder extends Seeder
{
    /** Canonical colours for the protected default statuses. */
    private const STATUS_COLORS = [
        'todo' => '#94a3b8',
        'in_progress' => '#3b82f6',
        'done' => '#22c55e',
    ];

    public function run(): void
    {
        // Idempotent: a fresh migrate wipes pm_* tables, so presence of any
        // workspace means we already seeded this run.
        if (Workspace::query()->exists()) {
            return;
        }

        $users = User::whereIn('email', [
            'hr@viteseo.ph', 'marco@viteseo.ph', 'employee@viteseo.ph',
            'leo@viteseo.ph', 'grace@viteseo.ph', 'bea@viteseo.ph',
            'carlo@viteseo.ph', 'nina@viteseo.ph', 'paolo@viteseo.ph',
        ])->get()->keyBy('email');

        // Without the HR demo people there is nothing to attach projects to.
        if ($users->isEmpty()) {
            return;
        }

        $u = fn (string $email): ?User => $users->get($email);

        // PMS roles. Managers/PMs get the management tier (pm_apm); everyone
        // else contributes as pm_member. Additive — a user can hold HR roles too.
        $managers = array_filter([$u('marco@viteseo.ph'), $u('employee@viteseo.ph'), $u('hr@viteseo.ph')]);
        foreach ($managers as $manager) {
            $manager->assignRole('pm_apm');
        }
        $members = array_filter([
            $u('leo@viteseo.ph'), $u('grace@viteseo.ph'), $u('bea@viteseo.ph'),
            $u('carlo@viteseo.ph'), $u('nina@viteseo.ph'), $u('paolo@viteseo.ph'),
        ]);
        foreach ($members as $member) {
            $member->assignRole('pm_member');
        }

        // DatabaseSeeder mutes model events (WithoutModelEvents), which would
        // suppress the HasPublicUuid `creating` hook that fills `uuid`. Restore
        // the real dispatcher for our work, then put it back. No-op standalone.
        $previousDispatcher = Model::getEventDispatcher();
        Model::setEventDispatcher(app('events'));

        try {
            DB::transaction(function () use ($u): void {
                $now = Carbon::now();

                $workspace = Workspace::create([
                    'name' => 'Viteseo Platform',
                    'description' => 'Product, engineering, and go-to-market delivery workspace.',
                ]);

                $this->seedWebsiteRevamp($workspace, $u, $now);
                $this->seedMobileApp($workspace, $u, $now);
                $this->seedMarketingCampaign($workspace, $u, $now);
            });
        } finally {
            Model::setEventDispatcher($previousDispatcher);
        }
    }

    /**
     * Active flagship project — the richest slice (groups, subtasks, comments,
     * links, activity, notifications) for the walkthrough.
     *
     * @param  callable(string):(?User)  $u
     */
    private function seedWebsiteRevamp(Workspace $workspace, callable $u, Carbon $now): void
    {
        $marco = $u('marco@viteseo.ph');
        $maya = $u('employee@viteseo.ph');
        $leo = $u('leo@viteseo.ph');
        $grace = $u('grace@viteseo.ph');
        $bea = $u('bea@viteseo.ph');

        $project = Project::create([
            'workspace_id' => $workspace->id,
            'name' => 'Website Revamp',
            'description' => 'Rebuild the marketing site on the new design system, ship a faster, on-brand experience.',
            'priority' => 'high',
            'color' => '#6366f1',
            'status' => ProjectState::ACTIVE,
        ]);

        $statuses = $this->seedStatuses($project);
        $this->attachMembers($project, [$marco, $maya, $leo, $grace, $bea]);

        $frontend = $this->makeGroup($project, 'Frontend Squad', '#0ea5e9', $leo, [$grace]);
        $design = $this->makeGroup($project, 'Design', '#ec4899', $bea, [$maya]);

        // 1) Individual task, in progress, with the full detail treatment.
        $home = Task::create([
            'project_id' => $project->id,
            'title' => 'Rebuild homepage hero section',
            'description' => 'New hero with animated headline, CTA, and responsive layout down to 320px.',
            'assignment_type' => AssignmentType::INDIVIDUAL,
            'assignee_id' => $leo?->id,
            'assigned_by_id' => $marco?->id,
            'status_id' => $statuses['in_progress']->id,
            'priority' => TaskPriority::HIGH,
            'effort_score' => 8,
            'start_date' => $now->copy()->subDays(2)->toDateString(),
            'deadline' => $now->copy()->addDays(5)->toDateString(),
        ]);
        $this->logCreated($home, $marco);
        $this->logStatusChange($home, $leo, $statuses['todo']->id, $statuses['in_progress']->id);
        $this->notifyAssigned($home, $leo, $marco);

        Subtask::create([
            'task_id' => $home->id,
            'name' => 'Implement responsive hero grid',
            'description' => 'CSS grid + clamp() typography.',
            'assignee_id' => $leo?->id,
            'status_id' => $statuses['in_progress']->id,
            'priority' => TaskPriority::HIGH,
            'effort_score' => 5,
            'deadline' => $now->copy()->addDays(3)->toDateString(),
        ]);
        Subtask::create([
            'task_id' => $home->id,
            'name' => 'Wire up CTA analytics events',
            'assignee_id' => $grace?->id,
            'status_id' => $statuses['todo']->id,
            'priority' => TaskPriority::MEDIUM,
            'effort_score' => 3,
        ]);

        $this->comment($home, $marco, 'Please keep the hero above the fold on mobile — check the 375px breakpoint.');
        $comment = $this->comment($home, $leo, 'On it. Grid is done, wiring the CTA next.');
        $this->notifyComment($home, $comment, $leo, $marco);
        $this->link($home, $leo, 'https://www.figma.com/file/5lX7DgqGbutmi9QfJryBRg/hero');

        // 2) Individual task, still to do.
        $nav = Task::create([
            'project_id' => $project->id,
            'title' => 'Design new top navigation',
            'description' => 'Sticky nav with mega-menu for product categories.',
            'assignment_type' => AssignmentType::INDIVIDUAL,
            'assignee_id' => $bea?->id,
            'assigned_by_id' => $maya?->id,
            'status_id' => $statuses['todo']->id,
            'priority' => TaskPriority::MEDIUM,
            'effort_score' => 5,
            'deadline' => $now->copy()->addDays(9)->toDateString(),
        ]);
        $this->logCreated($nav, $maya);
        $this->notifyAssigned($nav, $bea, $maya);

        // 3) Group task assigned to the Frontend Squad (no individual assignee).
        $perf = Task::create([
            'project_id' => $project->id,
            'title' => 'Improve Lighthouse performance score to 90+',
            'description' => 'Image optimisation, code-splitting, and font preloading.',
            'assignment_type' => AssignmentType::GROUP,
            'group_id' => $frontend->id,
            'assignee_id' => null,
            'assigned_by_id' => $marco?->id,
            'status_id' => $statuses['in_progress']->id,
            'priority' => TaskPriority::HIGH,
            'effort_score' => null,
            'deadline' => $now->copy()->addDays(12)->toDateString(),
        ]);
        $this->logCreated($perf, $marco);

        // 4) A custom-status ("In Review") individual task.
        $copy = Task::create([
            'project_id' => $project->id,
            'title' => 'Finalise landing-page copy',
            'assignment_type' => AssignmentType::INDIVIDUAL,
            'assignee_id' => $maya?->id,
            'assigned_by_id' => $marco?->id,
            'status_id' => $statuses['review']->id,
            'priority' => TaskPriority::MEDIUM,
            'effort_score' => 3,
            'deadline' => $now->copy()->addDays(2)->toDateString(),
        ]);
        $this->logCreated($copy, $marco);

        // 5) A completed task, for board/history realism.
        $setup = Task::create([
            'project_id' => $project->id,
            'title' => 'Set up design tokens in the codebase',
            'assignment_type' => AssignmentType::INDIVIDUAL,
            'assignee_id' => $leo?->id,
            'assigned_by_id' => $marco?->id,
            'status_id' => $statuses['done']->id,
            'priority' => TaskPriority::LOW,
            'effort_score' => 2,
            'start_date' => $now->copy()->subDays(10)->toDateString(),
            'deadline' => $now->copy()->subDays(4)->toDateString(),
        ]);
        $this->logCreated($setup, $marco);
        $this->logStatusChange($setup, $leo, $statuses['in_progress']->id, $statuses['done']->id);
    }

    /**
     * Second active project — lighter, but exercises a second group + task set.
     *
     * @param  callable(string):(?User)  $u
     */
    private function seedMobileApp(Workspace $workspace, callable $u, Carbon $now): void
    {
        $marco = $u('marco@viteseo.ph');
        $leo = $u('leo@viteseo.ph');
        $carlo = $u('carlo@viteseo.ph');
        $maya = $u('employee@viteseo.ph');

        $project = Project::create([
            'workspace_id' => $workspace->id,
            'name' => 'Mobile App v2',
            'description' => 'Native rewrite of the companion app with offline support and push notifications.',
            'priority' => 'medium',
            'color' => '#f59e0b',
            'status' => ProjectState::ACTIVE,
        ]);

        $statuses = $this->seedStatuses($project);
        $this->attachMembers($project, [$marco, $leo, $carlo, $maya]);

        $mobile = $this->makeGroup($project, 'Mobile Team', '#10b981', $marco, [$leo, $carlo]);

        $offline = Task::create([
            'project_id' => $project->id,
            'title' => 'Implement offline sync queue',
            'description' => 'Queue mutations locally and replay on reconnect with conflict resolution.',
            'assignment_type' => AssignmentType::GROUP,
            'group_id' => $mobile->id,
            'assignee_id' => null,
            'assigned_by_id' => $marco?->id,
            'status_id' => $statuses['todo']->id,
            'priority' => TaskPriority::HIGH,
            'effort_score' => null,
            'deadline' => $now->copy()->addDays(20)->toDateString(),
        ]);
        $this->logCreated($offline, $marco);

        $push = Task::create([
            'project_id' => $project->id,
            'title' => 'Wire push notifications (FCM + APNs)',
            'assignment_type' => AssignmentType::INDIVIDUAL,
            'assignee_id' => $carlo?->id,
            'assigned_by_id' => $marco?->id,
            'status_id' => $statuses['in_progress']->id,
            'priority' => TaskPriority::MEDIUM,
            'effort_score' => 8,
            'start_date' => $now->copy()->subDays(1)->toDateString(),
            'deadline' => $now->copy()->addDays(14)->toDateString(),
        ]);
        $this->logCreated($push, $marco);
        $this->notifyAssigned($push, $carlo, $marco);
        $this->link($push, $carlo, 'https://firebase.google.com/docs/cloud-messaging');
    }

    /**
     * A completed (done) project so the "Done" project filter has data.
     *
     * @param  callable(string):(?User)  $u
     */
    private function seedMarketingCampaign(Workspace $workspace, callable $u, Carbon $now): void
    {
        $maya = $u('employee@viteseo.ph');
        $nina = $u('nina@viteseo.ph');
        $carlo = $u('carlo@viteseo.ph');

        $project = Project::create([
            'workspace_id' => $workspace->id,
            'name' => 'Q2 Marketing Campaign',
            'description' => 'Spring product launch campaign across email, paid, and social. Wrapped and archived.',
            'priority' => 'low',
            'color' => '#8b5cf6',
            'status' => ProjectState::DONE,
        ]);

        $statuses = $this->seedStatuses($project);
        $this->attachMembers($project, [$maya, $nina, $carlo]);

        foreach ([
            ['Launch email drip sequence', $nina, TaskPriority::MEDIUM, 5],
            ['Run paid social A/B test', $carlo, TaskPriority::HIGH, 8],
            ['Publish campaign recap report', $maya, TaskPriority::LOW, 3],
        ] as [$title, $assignee, $priority, $effort]) {
            $task = Task::create([
                'project_id' => $project->id,
                'title' => $title,
                'assignment_type' => AssignmentType::INDIVIDUAL,
                'assignee_id' => $assignee?->id,
                'assigned_by_id' => $maya?->id,
                'status_id' => $statuses['done']->id,
                'priority' => $priority,
                'effort_score' => $effort,
                'start_date' => $now->copy()->subDays(40)->toDateString(),
                'deadline' => $now->copy()->subDays(20)->toDateString(),
            ]);
            $this->logCreated($task, $maya);
        }
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * Seed the 3 protected default statuses (mirrors ProjectService::create)
     * plus a custom "In Review" column.
     *
     * @return array<string, ProjectStatus>  keyed by status key + 'review'
     */
    private function seedStatuses(Project $project): array
    {
        $statuses = [];
        foreach (StatusKey::defaults() as $order => $key) {
            $statuses[$key->value] = ProjectStatus::create([
                'project_id' => $project->id,
                'name' => $key->label(),
                'key' => $key,
                'color' => self::STATUS_COLORS[$key->value],
                'is_protected' => true,
                'order' => $order,
            ]);
        }

        $statuses['review'] = ProjectStatus::create([
            'project_id' => $project->id,
            'name' => 'In Review',
            'key' => StatusKey::CUSTOM,
            'color' => '#f97316',
            'is_protected' => false,
            'order' => count(StatusKey::defaults()),
        ]);

        return $statuses;
    }

    /**
     * @param  array<int, ?User>  $users
     */
    private function attachMembers(Project $project, array $users): void
    {
        $ids = array_values(array_filter(array_map(fn (?User $user) => $user?->id, $users)));
        $project->members()->syncWithoutDetaching($ids);
    }

    /**
     * @param  array<int, ?User>  $memberUsers
     */
    private function makeGroup(Project $project, string $name, string $color, ?User $leader, array $memberUsers): Group
    {
        $group = Group::create([
            'name' => $name,
            'color' => $color,
            'leader_id' => $leader?->id,
            'archived' => false,
        ]);

        $ids = array_values(array_filter(array_map(fn (?User $user) => $user?->id, $memberUsers)));
        $group->members()->syncWithoutDetaching($ids);

        // Groups relate to projects via the pivot now; attach this demo group to
        // the project it was built for (its members are already project members).
        $group->projects()->syncWithoutDetaching([$project->id => ['created_at' => now()]]);

        return $group;
    }

    private function logCreated(Task $task, ?User $actor): void
    {
        if ($actor === null) {
            return;
        }

        ActivityLog::create([
            'task_id' => $task->id,
            'user_id' => $actor->id,
            'action' => 'task.created',
        ]);
    }

    private function logStatusChange(Task $task, ?User $actor, int $oldStatusId, int $newStatusId): void
    {
        if ($actor === null) {
            return;
        }

        ActivityLog::create([
            'task_id' => $task->id,
            'user_id' => $actor->id,
            'action' => 'task.status_changed',
            'field_changed' => 'status_id',
            'old_value' => (string) $oldStatusId,
            'new_value' => (string) $newStatusId,
        ]);
    }

    private function comment(Task $task, ?User $author, string $content): ?Comment
    {
        if ($author === null) {
            return null;
        }

        return Comment::create([
            'task_id' => $task->id,
            'author_id' => $author->id,
            'content' => $content,
        ]);
    }

    private function link(Task $task, ?User $user, string $url): void
    {
        if ($user === null) {
            return;
        }

        Link::create([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'url' => $url,
        ]);
    }

    private function notifyAssigned(Task $task, ?User $recipient, ?User $actor): void
    {
        if ($recipient === null || $actor === null || $recipient->id === $actor->id) {
            return;
        }

        Notification::create([
            'user_id' => $recipient->id,
            'actor_id' => $actor->id,
            'type' => 'task.assigned',
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'data' => ['title' => $task->title],
        ]);
    }

    private function notifyComment(Task $task, ?Comment $comment, ?User $author, ?User $recipient): void
    {
        if ($comment === null || $author === null || $recipient === null || $recipient->id === $author->id) {
            return;
        }

        Notification::create([
            'user_id' => $recipient->id,
            'actor_id' => $author->id,
            'type' => 'comment.created',
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'comment_id' => $comment->id,
            'data' => [
                'title' => $task->title,
                'snippet' => str($comment->content)->limit(80)->value(),
            ],
        ]);
    }
}
