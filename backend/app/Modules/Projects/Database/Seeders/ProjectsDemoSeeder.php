<?php

declare(strict_types=1);

namespace App\Modules\Projects\Database\Seeders;

use App\Models\User;
use App\Modules\Projects\Enums\ApprovalStatus;
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
                $this->seedDesignSystem($workspace, $u, $now);
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
            'name' => 'Sagip Dental Clinic — Website Revamp',
            'description' => 'Rebuild the clinic site, add online appointment booking, and get the pages loading fast on a phone.',
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
            'description' => 'New headline, a Book an appointment button, and a layout that holds down to 320px.',
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

        $heroGrid = Subtask::create([
            'task_id' => $home->id,
            'name' => 'Implement responsive hero grid',
            'description' => 'CSS grid + clamp() typography.',
            'assignee_id' => $leo?->id,
            'status_id' => $statuses['in_progress']->id,
            'priority' => TaskPriority::HIGH,
            'effort_score' => 5,
            'deadline' => $now->copy()->addDays(3)->toDateString(),
        ]);
        $this->logSubtaskCreated($heroGrid, $marco);
        $this->logSubtaskStatusChange($heroGrid, $leo, $statuses['todo']->id);

        $ctaEvents = Subtask::create([
            'task_id' => $home->id,
            'name' => 'Wire up the Book appointment click tracking',
            'assignee_id' => $grace?->id,
            'status_id' => $statuses['todo']->id,
            'priority' => TaskPriority::MEDIUM,
            'effort_score' => 3,
        ]);
        $this->logSubtaskCreated($ctaEvents, $marco);

        $this->comment($home, $marco, 'Please keep the hero above the fold on mobile — check the 375px breakpoint.');
        $comment = $this->comment($home, $leo, 'On it. Grid is done, wiring the Book appointment button next.');
        $this->notifyComment($home, $comment, $leo, $marco);
        $this->link($home, $leo, 'https://www.figma.com/file/5lX7DgqGbutmi9QfJryBRg/hero');

        // 2) Individual task, still to do.
        $nav = Task::create([
            'project_id' => $project->id,
            'title' => 'Design new top navigation',
            'description' => 'Sticky menu with the services grouped under one heading.',
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

        // Broken down, so the group's workload comes from its sub-tasks (the
        // parent contributes 0) and each piece also lands on its own assignee.
        foreach ([
            ['Ship responsive image pipeline', $leo, TaskPriority::HIGH, 8, 'in_progress'],
            ['Code-split the marketing bundle', $grace, TaskPriority::HIGH, 7, 'todo'],
            ['Preload brand fonts', $bea, TaskPriority::MEDIUM, 3, 'todo'],
        ] as [$name, $assignee, $priority, $effort, $status]) {
            $subtask = Subtask::create([
                'task_id' => $perf->id,
                'name' => $name,
                'assignee_id' => $assignee?->id,
                'status_id' => $statuses[$status]->id,
                'priority' => $priority,
                'effort_score' => $effort,
                'deadline' => $now->copy()->addDays(10)->toDateString(),
            ]);
            $this->logSubtaskCreated($subtask, $marco);
            $this->logSubtaskStatusChange($subtask, $assignee, $statuses['todo']->id);
        }

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
            'title' => 'Set the brand colours and type in the theme',
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

        // 6) A member's task proposal awaiting PM review, with a bundled sub-task.
        //    Nobody but Leo (and the review queue) can see either row.
        $audit = Task::create([
            'project_id' => $project->id,
            'title' => 'Audit the site for broken internal links',
            'description' => 'Crawl every published page and fix or redirect the 404s before launch.',
            'assignment_type' => AssignmentType::INDIVIDUAL,
            'assignee_id' => $leo?->id,
            'assigned_by_id' => $leo?->id,
            'status_id' => $statuses['todo']->id,
            'priority' => TaskPriority::MEDIUM,
            'effort_score' => 4,
            'deadline' => $now->copy()->addDays(7)->toDateString(),
            'approval_status' => ApprovalStatus::PENDING,
            'submitted_by_id' => $leo?->id,
        ]);
        $this->logCreated($audit, $leo);
        $crawler = Subtask::create([
            'task_id' => $audit->id,
            'name' => 'Run the crawler across staging',
            'assignee_id' => $leo?->id,
            'status_id' => $statuses['todo']->id,
            'priority' => TaskPriority::MEDIUM,
            'effort_score' => 2,
            'approval_status' => ApprovalStatus::PENDING,
            'submitted_by_id' => $leo?->id,
        ]);
        $this->logSubtaskCreated($crawler, $leo);

        // 7) A proposal a PM already sent back — Grace can revise and resubmit it.
        $rejected = Task::create([
            'project_id' => $project->id,
            'title' => 'Rewrite the services page in a warmer tone',
            'description' => 'Plain language, patient first, across all three service pages.',
            'assignment_type' => AssignmentType::INDIVIDUAL,
            'assignee_id' => $grace?->id,
            'assigned_by_id' => $grace?->id,
            'status_id' => $statuses['todo']->id,
            'priority' => TaskPriority::LOW,
            'effort_score' => 6,
            'deadline' => $now->copy()->addDays(14)->toDateString(),
            'approval_status' => ApprovalStatus::REJECTED,
            'submitted_by_id' => $grace?->id,
            'reviewed_by_id' => $marco?->id,
            'reviewed_at' => $now->copy()->subDay(),
            'rejection_reason' => 'Pricing copy is locked until the Q4 packaging review lands. Re-submit after 15 Nov with the new tiers.',
        ]);
        $this->logCreated($rejected, $grace);

        // 8) A stand-alone sub-task proposal on an already-approved task — this one
        //    queues on its own, not as part of a bundle.
        $keyboardNav = Subtask::create([
            'task_id' => $nav->id,
            'name' => 'Add keyboard navigation to the services menu',
            'description' => 'Arrow keys + Escape, focus trap while open.',
            'assignee_id' => $bea?->id,
            'status_id' => $statuses['todo']->id,
            'priority' => TaskPriority::MEDIUM,
            'effort_score' => 3,
            'approval_status' => ApprovalStatus::PENDING,
            'submitted_by_id' => $bea?->id,
        ]);
        $this->logSubtaskCreated($keyboardNav, $bea);

        // 9) Open tasks — posted by a member with no assignee, so anyone may
        //    claim them. Approved on the spot: an unowned task commits nobody, so
        //    there is nothing for a PM to review until someone takes it.
        $this->openTask(
            $project,
            $statuses['todo'],
            $leo,
            'Audit the appointment form for dead ends',
            'Walk the full booking path and note every screen with no way forward.',
            TaskPriority::HIGH,
            5,
            $now->copy()->addDays(6)->toDateString(),
        );
        $this->openTask(
            $project,
            $statuses['todo'],
            $grace,
            'Write alt text for the new marketing imagery',
            null,
            TaskPriority::LOW,
            2,
            $now->copy()->addDays(11)->toDateString(),
        );
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
            'name' => 'Northpoint Hardware — Ecommerce Build',
            'description' => 'Stand up the online store, migrate the 1,200-item catalogue, and wire up local delivery.',
            'priority' => 'medium',
            'color' => '#f59e0b',
            'status' => ProjectState::ACTIVE,
        ]);

        $statuses = $this->seedStatuses($project);
        $this->attachMembers($project, [$marco, $leo, $carlo, $maya]);

        $mobile = $this->makeGroup($project, 'Ecommerce Team', '#10b981', $marco, [$leo, $carlo]);

        $offline = Task::create([
            'project_id' => $project->id,
            'title' => 'Import the 1,200-item product catalogue',
            'description' => 'Map the supplier spreadsheet onto product, variant and stock fields.',
            'assignment_type' => AssignmentType::GROUP,
            'group_id' => $mobile->id,
            'assignee_id' => null,
            'assigned_by_id' => $marco?->id,
            'status_id' => $statuses['todo']->id,
            'priority' => TaskPriority::HIGH,
            // Sized but not broken down — the group carries this one directly.
            'effort_score' => 9,
            'deadline' => $now->copy()->addDays(20)->toDateString(),
        ]);
        $this->logCreated($offline, $marco);

        $release = Task::create([
            'project_id' => $project->id,
            'title' => 'Prepare the launch checklist',
            'description' => 'Payments tested, stock synced, staging signed off, and a rollback plan.',
            'assignment_type' => AssignmentType::GROUP,
            'group_id' => $mobile->id,
            'assignee_id' => null,
            'assigned_by_id' => $marco?->id,
            'status_id' => $statuses['in_progress']->id,
            'priority' => TaskPriority::MEDIUM,
            'effort_score' => null,
            'deadline' => $now->copy()->addDays(25)->toDateString(),
        ]);
        $this->logCreated($release, $marco);

        foreach ([
            ['Draft the product photos and descriptions', $leo, TaskPriority::LOW, 2, 'todo'],
            ['Wire order alerts to the shop inbox', $carlo, TaskPriority::HIGH, 6, 'in_progress'],
        ] as [$name, $assignee, $priority, $effort, $status]) {
            $subtask = Subtask::create([
                'task_id' => $release->id,
                'name' => $name,
                'assignee_id' => $assignee?->id,
                'status_id' => $statuses[$status]->id,
                'priority' => $priority,
                'effort_score' => $effort,
                'deadline' => $now->copy()->addDays(22)->toDateString(),
            ]);
            $this->logSubtaskCreated($subtask, $marco);
            $this->logSubtaskStatusChange($subtask, $assignee, $statuses['todo']->id);
        }

        $push = Task::create([
            'project_id' => $project->id,
            'title' => 'Wire up local delivery and pickup rates',
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
            'name' => 'Aling Nenas Bakeshop — Local SEO Campaign',
            'description' => 'Google Business Profile, local citations and barangay landing pages. Delivered and archived.',
            'priority' => 'low',
            'color' => '#8b5cf6',
            'status' => ProjectState::DONE,
        ]);

        $statuses = $this->seedStatuses($project);
        $this->attachMembers($project, [$maya, $nina, $carlo]);

        foreach ([
            ['Claim and fill the Google Business Profile', $nina, TaskPriority::MEDIUM, 5],
            ['Build the barangay landing pages', $carlo, TaskPriority::HIGH, 8],
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

    /**
     * Fourth active project, seeded specifically so Workload Analysis has
     * something to analyse.
     *
     * It spreads active work across every member and both groups, and covers all
     * three task categories (Light 1-3, Moderate 4-7, Heavy 8-10) so the KPI
     * cards, the stacked bars and the Category filter all have data. Effort is
     * chosen so the six members land in visibly different bands against the
     * default 20/40 thresholds — a flat team would make the chart pointless.
     *
     * Some work is Done on purpose: it must NOT appear in workload totals, and
     * it gives the member page's completion percentage something to report.
     *
     * @param  callable(string):(?User)  $u
     */
    private function seedDesignSystem(Workspace $workspace, callable $u, Carbon $now): void
    {
        $maya = $u('employee@viteseo.ph');
        $marco = $u('marco@viteseo.ph');
        $leo = $u('leo@viteseo.ph');
        $grace = $u('grace@viteseo.ph');
        $bea = $u('bea@viteseo.ph');
        $carlo = $u('carlo@viteseo.ph');
        $nina = $u('nina@viteseo.ph');
        $paolo = $u('paolo@viteseo.ph');

        $project = Project::create([
            'workspace_id' => $workspace->id,
            'name' => 'Cebu Island Tours — Booking Site & SEO',
            'description' => 'Booking site for the tour packages, plus an SEO push on the tour keywords.',
            'priority' => 'high',
            'color' => '#0ea5e9',
            'status' => ProjectState::ACTIVE,
        ]);

        $statuses = $this->seedStatuses($project);
        $this->attachMembers($project, [$maya, $marco, $leo, $grace, $bea, $carlo, $nina, $paolo]);

        $platform = $this->makeGroup($project, 'SEO Team', '#6366f1', $grace, [$bea, $paolo]);

        // Individually-owned work. [title, assignee, priority, effort, status]
        foreach ([
            ['Check the tour pages for readable colour contrast', $grace, TaskPriority::HIGH, 9, 'in_progress'],
            ['Design the booking flow', $bea, TaskPriority::HIGH, 8, 'todo'],
            ['Rebuild the booking widget', $leo, TaskPriority::HIGH, 7, 'in_progress'],
            ['Tidy up the tour card layout', $carlo, TaskPriority::MEDIUM, 6, 'todo'],
            ['Document the itinerary content model', $nina, TaskPriority::MEDIUM, 5, 'todo'],
            ['Write the tour listing style guide', $paolo, TaskPriority::LOW, 4, 'in_progress'],
            ['Publish the initial accessibility report', $nina, TaskPriority::LOW, 3, 'todo'],
            ['Redirect the old tour URLs', $paolo, TaskPriority::LOW, 2, 'todo'],
            ['Add smoke tests for the booking form', $carlo, TaskPriority::MEDIUM, 7, 'in_progress'],
            ['Move the enquiry pop-up to the new form', $leo, TaskPriority::HIGH, 8, 'todo'],
            ['Retire the old theme', $bea, TaskPriority::LOW, 1, 'todo'],
            // Two people deliberately pushed over the Heavy line (>40 by default):
            // an analysis page where nobody is overloaded has nothing to show.
            ['Rewrite the availability calendar', $leo, TaskPriority::HIGH, 8, 'in_progress'],
            ['Untangle the old page-builder markup', $leo, TaskPriority::MEDIUM, 5, 'todo'],
            ['Rebuild the tour comparison table', $carlo, TaskPriority::HIGH, 10, 'todo'],
            ['Backfill schema markup on the tour pages', $carlo, TaskPriority::MEDIUM, 6, 'in_progress'],
            // Finished work — deliberately excluded from every workload total.
            ['Agree the URL and slug convention', $grace, TaskPriority::MEDIUM, 5, 'done'],
            ['Set up the staging site', $leo, TaskPriority::LOW, 3, 'done'],
            ['Inventory the existing tour pages', $nina, TaskPriority::LOW, 2, 'done'],
        ] as [$title, $assignee, $priority, $effort, $status]) {
            $task = Task::create([
                'project_id' => $project->id,
                'title' => $title,
                'assignment_type' => AssignmentType::INDIVIDUAL,
                'assignee_id' => $assignee?->id,
                'assigned_by_id' => $maya?->id,
                'status_id' => $statuses[$status]->id,
                'priority' => $priority,
                'effort_score' => $effort,
                'start_date' => $now->copy()->subDays(3)->toDateString(),
                'deadline' => $now->copy()->addDays(12)->toDateString(),
            ]);
            $this->logCreated($task, $maya);
        }

        // A group task carried whole — the Platform Guild owns it directly.
        $tokens = Task::create([
            'project_id' => $project->id,
            'title' => 'Ship the sitemap and robots.txt',
            'description' => 'Generate it from the live tour pages and submit it to Search Console.',
            'assignment_type' => AssignmentType::GROUP,
            'group_id' => $platform->id,
            'assignee_id' => null,
            'assigned_by_id' => $maya?->id,
            'status_id' => $statuses['in_progress']->id,
            'priority' => TaskPriority::HIGH,
            'effort_score' => 7,
            'deadline' => $now->copy()->addDays(18)->toDateString(),
        ]);
        $this->logCreated($tokens, $maya);

        // ...and one broken into sub-tasks, so the group's effort comes from the
        // pieces while each piece also lands on its own assignee.
        $docs = Task::create([
            'project_id' => $project->id,
            'title' => 'Launch the booking site',
            'assignment_type' => AssignmentType::GROUP,
            'group_id' => $platform->id,
            'assignee_id' => null,
            'assigned_by_id' => $maya?->id,
            'status_id' => $statuses['in_progress']->id,
            'priority' => TaskPriority::MEDIUM,
            'effort_score' => null,
            'deadline' => $now->copy()->addDays(21)->toDateString(),
        ]);
        $this->logCreated($docs, $maya);

        foreach ([
            ['Write the tour copy guidelines', $bea, TaskPriority::MEDIUM, 6, 'todo'],
            ['Build the live availability preview', $paolo, TaskPriority::HIGH, 8, 'in_progress'],
            ['Wire search across the tour listings', $grace, TaskPriority::LOW, 3, 'todo'],
            ['Port the old blog posts', $paolo, TaskPriority::LOW, 2, 'done'],
        ] as [$name, $assignee, $priority, $effort, $status]) {
            $subtask = Subtask::create([
                'task_id' => $docs->id,
                'name' => $name,
                'assignee_id' => $assignee?->id,
                'status_id' => $statuses[$status]->id,
                'priority' => $priority,
                'effort_score' => $effort,
                'deadline' => $now->copy()->addDays(19)->toDateString(),
            ]);
            $this->logSubtaskCreated($subtask, $maya);
            $this->logSubtaskStatusChange($subtask, $assignee, $statuses['todo']->id);
        }

        $this->comment($tokens, $marco, 'Put it on staging first so we can test the booking end to end.');
        $this->link($tokens, $grace, 'https://www.figma.com/design/2sxepYV9HjaqX6zEb53P72/Pod---3-Project-Management-System');
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

    /**
     * A task on the open pool: individual, unassigned, approved, and stamped with
     * the member who put it up (that is who a claim notifies).
     */
    private function openTask(
        Project $project,
        ProjectStatus $status,
        ?User $poster,
        string $title,
        ?string $description,
        TaskPriority $priority,
        int $effort,
        string $deadline,
    ): ?Task {
        if ($poster === null) {
            return null;
        }

        $task = Task::create([
            'project_id' => $project->id,
            'title' => $title,
            'description' => $description,
            'assignment_type' => AssignmentType::INDIVIDUAL,
            'assignee_id' => null,
            'assigned_by_id' => null,
            'status_id' => $status->id,
            'priority' => $priority,
            'effort_score' => $effort,
            'deadline' => $deadline,
            'approval_status' => ApprovalStatus::APPROVED,
            'submitted_by_id' => $poster->id,
        ]);

        $this->logCreated($task, $poster);

        return $task;
    }

    private function logSubtaskCreated(Subtask $subtask, ?User $actor): void
    {
        if ($actor === null) {
            return;
        }

        ActivityLog::create([
            'task_id' => $subtask->task_id,
            'subtask_id' => $subtask->id,
            'user_id' => $actor->id,
            'action' => 'subtask.created',
        ]);
    }

    /**
     * Sub-tasks are seeded straight into the status they are "already" in, so
     * the move the assignee would have made is recorded here — otherwise the
     * activity feed only ever shows sub-tasks being created, never progressing.
     */
    private function logSubtaskStatusChange(Subtask $subtask, ?User $actor, int $fromStatusId): void
    {
        if ($actor === null || (int) $subtask->status_id === $fromStatusId) {
            return;
        }

        ActivityLog::create([
            'task_id' => $subtask->task_id,
            'subtask_id' => $subtask->id,
            'user_id' => $actor->id,
            'action' => 'subtask.status_changed',
            'field_changed' => 'status_id',
            'old_value' => (string) $fromStatusId,
            'new_value' => (string) $subtask->status_id,
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
