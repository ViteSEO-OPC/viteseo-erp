<?php

declare(strict_types=1);

use App\Modules\Projects\Controllers\TeamController;
use App\Modules\Projects\Controllers\ActivityLogController;
use App\Modules\Projects\Controllers\ApprovalController;
use App\Modules\Projects\Controllers\ArchiveController;
use App\Modules\Projects\Controllers\CommentController;
use App\Modules\Projects\Controllers\GroupController;
use App\Modules\Projects\Controllers\LinkController;
use App\Modules\Projects\Controllers\NoteController;
use App\Modules\Projects\Controllers\NotificationController;
use App\Modules\Projects\Controllers\ProjectController;
use App\Modules\Projects\Controllers\ProjectStatusController;
use App\Modules\Projects\Controllers\SearchController;
use App\Modules\Projects\Controllers\SubtaskController;
use App\Modules\Projects\Controllers\TaskController;
use App\Modules\Projects\Controllers\TaskImportController;
use App\Modules\Projects\Controllers\UserDirectoryController;
use App\Modules\Projects\Controllers\WorkloadAnalysisController;
use App\Modules\Projects\Controllers\WorkloadController;
use App\Modules\Projects\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rate limiting
|--------------------------------------------------------------------------
| The platform defines no global `api` limiter, so without the group throttle
| below every route here is unmetered. It is set deliberately high — this is a
| backstop against a runaway client or a scripted loop, not a usage quota, and
| a busy board legitimately makes a lot of calls.
|
| ALWAYS name the bucket (the trailing `:` form). Laravel keys an unprefixed
| `throttle:` on the authenticated user ALONE — the route plays no part — so
| every unprefixed throttle in a module quietly shares ONE counter per user.
| HR hit this and documented it; the two throttles that already existed here
| (search and note autosave) were sharing a counter with each other for the
| same reason, and now no longer do.
|
| Scoped to this group on purpose: a global limiter belongs in bootstrap/app.php,
| which is shared platform config and would silently change HR and CRM too.
*/
Route::prefix('v1/projects')
/*
 * THE THREE GATES, added 2026-09-03. Until then this module had NONE of them, and
 * a plan was a price list: an org could avail Basic and reach every route Full
 * sells, and an org whose subscription had lapsed -- or that never had one -- kept
 * working exactly as before.
 *
 *   set-current-org      pins the tenant for the whole request, before anything reads
 *   subscription-active  402s a lapsed or absent subscription (super_admin bypasses)
 *   feature:pms     refuses an org that never availed this module at all
 *
 * Order matters: subscription-active and feature both ask CurrentOrg who the tenant
 * is, so set-current-org has to run first.
 */
    ->middleware(['auth:sanctum', 'set-current-org', 'subscription-active', 'feature:pms', 'throttle:300,1,projects:'])
    ->group(function (): void {
        // User directory for member/assignee pickers (shared users table).
        //
        // Gated: this reads the PLATFORM-WIDE users table and returns names and
        // email addresses, so `auth:sanctum` alone let any CRM or HR account
        // enumerate the whole staff directory. `projects.projects.view` is the
        // marker for "has Projects access at all" — both pm tiers hold it, no
        // other role does.
        Route::get('users', [UserDirectoryController::class, 'index'])
            ->can('projects.projects.view');

        // Global search (topbar jump-to menu) across projects and tasks. Called on
        // a debounced keystroke, so it carries its own throttle.
        Route::get('search', [SearchController::class, 'index'])
            ->middleware('throttle:120,1,projects-search:');

        /*
         * Org-level team management: the CURRENT org's Projects staff.
         *
         * How a client's own super admin gives their people accounts -- one half
         * of "create roles, then create the people who hold them". Guarded three
         * ways and none of them is the URL: the admin check lives in
         * TeamController::ensureAdmin, membership is fenced to the current org,
         * and the role a member may be given is restricted to this module's
         * presets. Seats are capped by the org's subscription.
         */
        Route::get('team', [TeamController::class, 'index']);
        Route::post('team', [TeamController::class, 'store']);
        Route::put('team/{user}', [TeamController::class, 'update']);
        Route::delete('team/{user}', [TeamController::class, 'destroy']);

        // Workspaces
        Route::apiResource('workspaces', WorkspaceController::class);

        // Groups
        Route::apiResource('groups', GroupController::class);
        Route::post('groups/{group}/members', [GroupController::class, 'addMember']);
        Route::delete('groups/{group}/members/{user}', [GroupController::class, 'removeMember']);

        // Projects
        Route::apiResource('projects', ProjectController::class);
        Route::post('projects/{project}/members', [ProjectController::class, 'addMember']);
        Route::delete('projects/{project}/members/{user}', [ProjectController::class, 'removeMember']);

        // Project statuses (nested for index/store, shallow for update/delete)
        Route::get('projects/{project}/statuses', [ProjectStatusController::class, 'index']);
        Route::post('projects/{project}/statuses', [ProjectStatusController::class, 'store']);
        Route::match(['put', 'patch'], 'statuses/{status}', [ProjectStatusController::class, 'update']);
        Route::delete('statuses/{status}', [ProjectStatusController::class, 'destroy']);

        // Workload — live active effort aggregation. Advisory only: the analysis
        // page, and the indicator loaded on demand when picking an assignee.
        //
        // The payload is still scoped by role inside the controller (managers see
        // the platform, members see their own work), but the ROUTES now also require
        // `projects.workload.view`. That permission already existed and is granted to
        // both pm tiers — these routes simply never checked it, so any authenticated
        // account on the platform, CRM and HR included, could read any member's or
        // any group's effort score.
        Route::middleware('can:projects.workload.view')->group(function (): void {
            // Aggregations over every active task/subtask — the heaviest reads here.
            Route::middleware('throttle:60,1,projects-workload:')->group(function (): void {
                Route::get('workload/analysis', [WorkloadAnalysisController::class, 'index']);
                Route::get('workload/analysis/tasks', [WorkloadAnalysisController::class, 'tasks']);
            });

            // Loaded on demand while picking an assignee, so it stays outside the
            // heavier analysis bucket.
            Route::get('members/{user}/workload', [WorkloadController::class, 'show']);
            Route::get('groups/{group}/workload', [WorkloadController::class, 'showGroup']);
        });

        // Retuning the bands is manager-only, enforced in the controller.
        Route::match(['put', 'patch'], 'workload/capacity', [WorkloadAnalysisController::class, 'updateCapacity']);

        // Tasks (nested index/store, shallow member routes)
        //
        // Bulk import is declared first: `tasks/import` is a literal segment and must
        // not be reachable as a task uuid. Manager-only (`projects.tasks.create`),
        // enforced in the controller and both form requests.
        //
        // It also gets its own throttle bucket: preview parses an upload, and commit
        // runs ~5 queries per imported item — a max-size plan is thousands of queries
        // in a single request, the most expensive thing a client can ask for here.
        Route::middleware('throttle:20,1,projects-import:')->group(function (): void {
            Route::get('projects/{project}/tasks/import/template', [TaskImportController::class, 'template']);
            Route::post('projects/{project}/tasks/import/preview', [TaskImportController::class, 'preview']);
            Route::post('projects/{project}/tasks/import', [TaskImportController::class, 'store']);
        });
        Route::get('projects/{project}/tasks', [TaskController::class, 'index']);
        Route::post('projects/{project}/tasks', [TaskController::class, 'store']);
        // The open-task pool and its two verbs. `tasks/claimable` MUST come before
        // `tasks/{task}` or the UUID binding swallows the literal segment.
        Route::get('tasks/claimable', [TaskController::class, 'claimable'])
            ->middleware('throttle:120,1,projects-claimable:');
        Route::post('tasks/{task}/claim', [TaskController::class, 'claim']);
        Route::post('tasks/{task}/release', [TaskController::class, 'release']);
        Route::get('tasks/{task}', [TaskController::class, 'show']);
        Route::match(['put', 'patch'], 'tasks/{task}', [TaskController::class, 'update']);
        Route::delete('tasks/{task}', [TaskController::class, 'destroy']);

        // Subtasks (nested index/store, shallow member routes)
        Route::get('tasks/{task}/subtasks', [SubtaskController::class, 'index']);
        Route::post('tasks/{task}/subtasks', [SubtaskController::class, 'store']);
        Route::get('subtasks/{subtask}', [SubtaskController::class, 'show']);
        Route::match(['put', 'patch'], 'subtasks/{subtask}', [SubtaskController::class, 'update']);
        Route::delete('subtasks/{subtask}', [SubtaskController::class, 'destroy']);

        // Comments (task XOR subtask parent)
        Route::get('tasks/{task}/comments', [CommentController::class, 'taskIndex']);
        Route::post('tasks/{task}/comments', [CommentController::class, 'taskStore']);
        Route::get('subtasks/{subtask}/comments', [CommentController::class, 'subtaskIndex']);
        Route::post('subtasks/{subtask}/comments', [CommentController::class, 'subtaskStore']);
        // Authors may revise their own comment; the original stays readable to all.
        Route::match(['put', 'patch'], 'comments/{comment}', [CommentController::class, 'update']);
        Route::delete('comments/{comment}', [CommentController::class, 'destroy']);

        // Links (task XOR subtask parent)
        Route::get('tasks/{task}/links', [LinkController::class, 'taskIndex']);
        Route::post('tasks/{task}/links', [LinkController::class, 'taskStore']);
        Route::get('subtasks/{subtask}/links', [LinkController::class, 'subtaskIndex']);
        Route::post('subtasks/{subtask}/links', [LinkController::class, 'subtaskStore']);
        Route::delete('links/{link}', [LinkController::class, 'destroy']);

        // Activity logs (read-only). The feed is the cross-project Recent Activity
        // list (task + sub-task events only); the nested route is one task's History.
        Route::get('activity-logs', [ActivityLogController::class, 'feed']);
        Route::get('tasks/{task}/activity-logs', [ActivityLogController::class, 'index']);

        // Private rich-text notes. Always "mine" — the note is resolved from the
        // token, never from a client-supplied id. Saves are autosaves (debounced
        // client-side), so the write routes carry a generous throttle.
        Route::get('notes/me', [NoteController::class, 'showMine']);
        Route::get('projects/{project}/notes/me', [NoteController::class, 'showMineForProject']);
        Route::middleware('throttle:60,1,projects-notes:')->group(function (): void {
            Route::put('notes/me', [NoteController::class, 'updateMine']);
            Route::put('projects/{project}/notes/me', [NoteController::class, 'updateMineForProject']);
        });

        // Notifications (scoped to the authenticated user). `read-all` before `{notification}`.
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead']);

        // Archives (Admin/PM only — enforced in controller)
        Route::prefix('admin/archives')->group(function (): void {
            Route::get('workspaces', [ArchiveController::class, 'workspaces']);
            Route::get('projects', [ArchiveController::class, 'projects']);
            Route::get('tasks', [ArchiveController::class, 'tasks']);
            Route::get('subtasks', [ArchiveController::class, 'subtasks']);

            Route::post('workspaces/{uuid}/restore', [ArchiveController::class, 'restoreWorkspace']);
            Route::post('projects/{uuid}/restore', [ArchiveController::class, 'restoreProject']);
            Route::post('tasks/{uuid}/restore', [ArchiveController::class, 'restoreTask']);
            Route::post('subtasks/{uuid}/restore', [ArchiveController::class, 'restoreSubtask']);
        });

        // Review queue for member-submitted work (Admin/PM only — enforced by the
        // `approve` policy ability). Reviewers EDIT a proposal through the ordinary
        // PATCH tasks/{task} and PATCH subtasks/{subtask} routes above.
        Route::prefix('admin/approvals')->group(function (): void {
            Route::get('tasks', [ApprovalController::class, 'tasks']);
            Route::get('subtasks', [ApprovalController::class, 'subtasks']);

            // Bulk routes come first: `tasks/bulk-approve` must not bind {task}.
            // Up to MAX_IDS decisions per call, each cascading a bundle.
            Route::middleware('throttle:30,1,projects-bulk-approve:')->group(function (): void {
                Route::post('tasks/bulk-approve', [ApprovalController::class, 'bulkApproveTasks']);
                Route::post('subtasks/bulk-approve', [ApprovalController::class, 'bulkApproveSubtasks']);
            });

            Route::post('tasks/{task}/approve', [ApprovalController::class, 'approveTask']);
            Route::post('tasks/{task}/reject', [ApprovalController::class, 'rejectTask']);
            Route::post('subtasks/{subtask}/approve', [ApprovalController::class, 'approveSubtask']);
            Route::post('subtasks/{subtask}/reject', [ApprovalController::class, 'rejectSubtask']);
        });

        // Author-side: send a revised (rejected) proposal back for review.
        Route::post('tasks/{task}/resubmit', [ApprovalController::class, 'resubmitTask']);
        Route::post('subtasks/{subtask}/resubmit', [ApprovalController::class, 'resubmitSubtask']);
    });
