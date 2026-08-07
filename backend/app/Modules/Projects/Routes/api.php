<?php

declare(strict_types=1);

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
use App\Modules\Projects\Controllers\UserDirectoryController;
use App\Modules\Projects\Controllers\WorkloadController;
use App\Modules\Projects\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/projects')->middleware('auth:sanctum')->group(function (): void {
    // User directory for member/assignee pickers (shared users table).
    Route::get('users', [UserDirectoryController::class, 'index']);

    // Global search (topbar jump-to menu) across projects and tasks. Called on
    // a debounced keystroke, so it carries its own throttle.
    Route::get('search', [SearchController::class, 'index'])->middleware('throttle:120,1');

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

    // Workload indicators — live active effort aggregation, loaded on demand
    // when selecting an assignee (member or group) in the task modal, and for
    // the Groups page. Advisory only.
    Route::get('members/{user}/workload', [WorkloadController::class, 'show']);
    Route::get('groups/{group}/workload', [WorkloadController::class, 'showGroup']);

    // Tasks (nested index/store, shallow member routes)
    Route::get('projects/{project}/tasks', [TaskController::class, 'index']);
    Route::post('projects/{project}/tasks', [TaskController::class, 'store']);
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
    Route::delete('comments/{comment}', [CommentController::class, 'destroy']);

    // Links (task XOR subtask parent)
    Route::get('tasks/{task}/links', [LinkController::class, 'taskIndex']);
    Route::post('tasks/{task}/links', [LinkController::class, 'taskStore']);
    Route::get('subtasks/{subtask}/links', [LinkController::class, 'subtaskIndex']);
    Route::post('subtasks/{subtask}/links', [LinkController::class, 'subtaskStore']);
    Route::delete('links/{link}', [LinkController::class, 'destroy']);

    // Activity logs (read-only, task-scoped)
    Route::get('tasks/{task}/activity-logs', [ActivityLogController::class, 'index']);

    // Private rich-text notes. Always "mine" — the note is resolved from the
    // token, never from a client-supplied id. Saves are autosaves (debounced
    // client-side), so the write routes carry a generous throttle.
    Route::get('notes/me', [NoteController::class, 'showMine']);
    Route::get('projects/{project}/notes/me', [NoteController::class, 'showMineForProject']);
    Route::middleware('throttle:60,1')->group(function (): void {
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
        Route::post('tasks/bulk-approve', [ApprovalController::class, 'bulkApproveTasks']);
        Route::post('subtasks/bulk-approve', [ApprovalController::class, 'bulkApproveSubtasks']);

        Route::post('tasks/{task}/approve', [ApprovalController::class, 'approveTask']);
        Route::post('tasks/{task}/reject', [ApprovalController::class, 'rejectTask']);
        Route::post('subtasks/{subtask}/approve', [ApprovalController::class, 'approveSubtask']);
        Route::post('subtasks/{subtask}/reject', [ApprovalController::class, 'rejectSubtask']);
    });

    // Author-side: send a revised (rejected) proposal back for review.
    Route::post('tasks/{task}/resubmit', [ApprovalController::class, 'resubmitTask']);
    Route::post('subtasks/{subtask}/resubmit', [ApprovalController::class, 'resubmitSubtask']);
});
