<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Enums\AssignmentType;
use App\Modules\Projects\Enums\UserRole;
use App\Modules\Projects\Events\SubtaskUpdated;
use App\Modules\Projects\Models\Subtask;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Requests\StoreSubtaskRequest;
use App\Modules\Projects\Requests\UpdateSubtaskRequest;
use App\Modules\Projects\Resources\SubtaskResource;
use App\Modules\Projects\Services\SubtaskService;
use App\Modules\Projects\Support\SafeBroadcast;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SubtaskController extends Controller
{
    public function __construct(private readonly SubtaskService $subtaskService) {}

    public function index(Request $request, Task $task): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Subtask::class);

        $subtasks = $task->subtasks()
            ->with(['assignee', 'status'])
            ->orderBy('created_at')
            ->paginate((int) $request->input('per_page', 15));

        return SubtaskResource::collection($subtasks);
    }

    public function store(StoreSubtaskRequest $request, Task $task): SubtaskResource
    {
        // Subtasks may be created by Admin/PM (permission) or, on a group-assigned
        // task, by that group's leader (the derived co-assignee, per the spec).
        $user = $request->user();
        $task->loadMissing('group');
        $isGroupLeader = $task->assignment_type === AssignmentType::GROUP
            && $task->group?->leader_id === $user->id;

        abort_unless($user->can('projects.subtasks.create') || $isGroupLeader, 403);

        $subtask = $this->subtaskService->create($task, $request->validated(), $user);

        return new SubtaskResource($subtask->load('task', 'assignee', 'status'));
    }

    public function show(Subtask $subtask): SubtaskResource
    {
        $this->authorize('view', $subtask);

        return new SubtaskResource($subtask->load('task', 'assignee', 'status'));
    }

    public function update(UpdateSubtaskRequest $request, Subtask $subtask): SubtaskResource
    {
        $this->authorize('update', $subtask);

        $user = $request->user();
        $data = $request->validated();

        // RBAC: Admin/PM and the parent group's leader may edit the full subtask.
        // Everyone else who reaches this point (the subtask's assignee) may change
        // the status only.
        $isManager = $user->hasAnyRole(UserRole::managerRoles());
        $subtask->loadMissing('task.group');
        $isGroupLeader = $subtask->task?->assignment_type === AssignmentType::GROUP
            && $subtask->task->group?->leader_id === $user->id;

        if (! $isManager && ! $isGroupLeader) {
            $data = array_intersect_key($data, array_flip(['status_id']));
        }

        $subtask = $this->subtaskService->update($subtask, $data, $user);
        $subtask->load('task', 'assignee', 'status');
        $subtask->task->loadMissing('project');

        SafeBroadcast::toOthers(new SubtaskUpdated(
            $subtask,
            (string) $subtask->task->project->uuid,
            (string) $subtask->task->uuid,
        ));

        return new SubtaskResource($subtask);
    }

    public function destroy(Subtask $subtask): Response
    {
        $this->authorize('delete', $subtask);

        $this->subtaskService->delete($subtask);

        return response()->noContent();
    }
}
