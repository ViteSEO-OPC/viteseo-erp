<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Enums\UserRole;
use App\Modules\Projects\Events\TaskCreated;
use App\Modules\Projects\Events\TaskDeleted;
use App\Modules\Projects\Events\TaskUpdated;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Requests\StoreTaskRequest;
use App\Modules\Projects\Requests\UpdateTaskRequest;
use App\Modules\Projects\Resources\TaskDetailResource;
use App\Modules\Projects\Resources\TaskResource;
use App\Modules\Projects\Resources\TaskSummaryResource;
use App\Modules\Projects\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TaskController extends Controller
{
    public function __construct(private readonly TaskService $taskService) {}

    /**
     * Board view: lightweight task list powering the list, kanban and calendar
     * views. Returns only the fields those views need — open a single task via
     * show() to load its full detail.
     */
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Task::class);
        // Members may only list tasks for a project they belong to.
        $this->authorize('view', $project);

        $tasks = $this->taskService->list(
            $project,
            $request->only([
                'status_id',
                'priority',
                'assignment_type',
                'assignee_id',
                'group_id',
                'search',
            ]),
            $request->input('sort'),
            (int) $request->input('per_page', 15),
            $request->user()?->id,
        );

        return TaskSummaryResource::collection($tasks);
    }

    public function store(StoreTaskRequest $request, Project $project): TaskResource
    {
        $this->authorize('create', Task::class);

        $task = $this->taskService->create($project, $request->validated(), $request->user());
        $task->load('project', 'status', 'group', 'assignee', 'assignedBy');

        broadcast(new TaskCreated($task))->toOthers();

        return new TaskResource($task);
    }

    /**
     * Detail view: full task payload (description + subtasks, comments, links
     * and activity logs), resolved by the task's public UUID from the URL.
     */
    public function show(Task $task): TaskDetailResource
    {
        $this->authorize('view', $task);

        return new TaskDetailResource($this->taskService->detail($task));
    }

    public function update(UpdateTaskRequest $request, Task $task): TaskResource
    {
        $this->authorize('update', $task);

        $data = $request->validated();

        // RBAC: only Admin/PM may edit the full task. Non-managers (e.g. the
        // assignee) may change the status only.
        $user = $request->user();
        $isManager = $user->hasAnyRole(UserRole::managerRoles());
        if (! $isManager) {
            $data = array_intersect_key($data, array_flip(['status_id']));
        }

        $task = $this->taskService->update($task, $data, $user);
        $task->load('project', 'status', 'group', 'assignee', 'assignedBy');

        broadcast(new TaskUpdated($task))->toOthers();

        return new TaskResource($task);
    }

    public function destroy(Task $task): Response
    {
        $this->authorize('delete', $task);

        // Capture identifiers before the (soft) delete so the broadcast payload
        // doesn't rely on re-fetching a trashed row.
        $taskUuid = (string) $task->uuid;
        $projectUuid = (string) $task->project->uuid;

        $this->taskService->delete($task);

        broadcast(new TaskDeleted($taskUuid, $projectUuid))->toOthers();

        return response()->noContent();
    }
}
