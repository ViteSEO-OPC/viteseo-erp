<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Modules\Projects\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Subtask;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\Workspace;
use App\Modules\Projects\Resources\ArchivedProjectResource;
use App\Modules\Projects\Resources\ArchivedSubtaskResource;
use App\Modules\Projects\Resources\ArchivedTaskResource;
use App\Modules\Projects\Resources\ArchivedWorkspaceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ArchiveController extends Controller
{
    /**
     * The archive is a management surface: Admins and PMs (the roles that can
     * soft-delete these entities) may browse and restore trashed items.
     */
    private function ensureManager(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user && $user->hasAnyRole(UserRole::managerRoles()),
            403,
            'Forbidden.',
        );
    }

    public function workspaces(Request $request): AnonymousResourceCollection
    {
        $this->ensureManager($request);

        $workspaces = Workspace::onlyTrashed()
            ->when($request->input('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('deleted_at', 'desc')
            ->paginate((int) $request->input('per_page', 15));

        return ArchivedWorkspaceResource::collection($workspaces);
    }

    public function projects(Request $request): AnonymousResourceCollection
    {
        $this->ensureManager($request);

        $projects = Project::onlyTrashed()
            // The parent workspace may itself be trashed — include it so its name still shows.
            ->with(['workspace' => fn ($q) => $q->withTrashed()])
            ->when($request->input('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('deleted_at', 'desc')
            ->paginate((int) $request->input('per_page', 15));

        return ArchivedProjectResource::collection($projects);
    }

    public function tasks(Request $request): AnonymousResourceCollection
    {
        $this->ensureManager($request);

        $tasks = Task::onlyTrashed()
            ->with(['project' => fn ($q) => $q->withTrashed(), 'status', 'assignee', 'group'])
            ->when($request->input('search'), fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->orderBy('deleted_at', 'desc')
            ->paginate((int) $request->input('per_page', 15));

        return ArchivedTaskResource::collection($tasks);
    }

    public function subtasks(Request $request): AnonymousResourceCollection
    {
        $this->ensureManager($request);

        $subtasks = Subtask::onlyTrashed()
            ->with([
                'task' => fn ($q) => $q->withTrashed()->with(['project' => fn ($p) => $p->withTrashed()]),
                'status',
                'assignee',
            ])
            ->when($request->input('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('deleted_at', 'desc')
            ->paginate((int) $request->input('per_page', 15));

        return ArchivedSubtaskResource::collection($subtasks);
    }

    public function restoreWorkspace(Request $request, string $uuid): JsonResponse
    {
        $this->ensureManager($request);

        $workspace = Workspace::onlyTrashed()->where('uuid', $uuid)->firstOrFail();
        $workspace->restore();

        return response()->json(['message' => 'Workspace restored.']);
    }

    public function restoreProject(Request $request, string $uuid): JsonResponse
    {
        $this->ensureManager($request);

        $project = Project::onlyTrashed()->where('uuid', $uuid)->firstOrFail();
        $project->restore();

        return response()->json(['message' => 'Project restored.']);
    }

    public function restoreTask(Request $request, string $uuid): JsonResponse
    {
        $this->ensureManager($request);

        $task = Task::onlyTrashed()->where('uuid', $uuid)->firstOrFail();
        $task->restore();

        return response()->json(['message' => 'Task restored.']);
    }

    public function restoreSubtask(Request $request, string $uuid): JsonResponse
    {
        $this->ensureManager($request);

        $subtask = Subtask::onlyTrashed()->where('uuid', $uuid)->firstOrFail();
        $subtask->restore();

        return response()->json(['message' => 'Subtask restored.']);
    }
}
