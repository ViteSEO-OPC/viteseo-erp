<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Enums\ApprovalStatus;
use App\Modules\Projects\Enums\AssignmentType;
use App\Modules\Projects\Enums\StatusKey;
use App\Modules\Projects\Enums\UserRole;
use App\Modules\Projects\Events\TaskCompleted;
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
use App\Modules\Projects\Services\NotificationService;
use App\Modules\Projects\Services\TaskService;
use App\Support\SafeBroadcast;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    public function __construct(
        private readonly TaskService $taskService,
        private readonly NotificationService $notifications,
    ) {}

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
        // A proposer must belong to the project they are proposing into
        // (ProjectPolicy::view enforces the roster for non-managers).
        $this->authorize('view', $project);

        $user = $request->user();
        $data = $request->validated();

        // Members hold `projects.tasks.propose`, not `.create`: their submission
        // is a proposal for a PM/Admin to review, always self-assigned. Everything
        // the client sent about the assignment target is overridden here.
        $isProposal = ! $user->can('projects.tasks.create');
        if ($isProposal) {
            $data['assignment_type'] = AssignmentType::INDIVIDUAL->value;
            $data['assignee_id'] = $user->id;
            $data['group_id'] = null;
            $data['approval_status'] = ApprovalStatus::PENDING;
            $data['submitted_by_id'] = $user->id;
        }

        $task = $this->taskService->create($project, $data, $user);
        $task->load('project', 'status', 'group', 'assignee', 'assignedBy', 'submittedBy');

        if ($isProposal) {
            // A pending task is on nobody's board yet, so there is nothing to
            // broadcast — TaskCreated fires on approval instead. The reviewers
            // hear about it through a notification.
            $this->notifications->notifyTaskReviewRequested($task, $user);
        } else {
            SafeBroadcast::toOthers(new TaskCreated($task));
        }

        return new TaskResource($task);
    }

    /**
     * Detail view: full task payload (description + subtasks, comments, links
     * and activity logs), resolved by the task's public UUID from the URL.
     */
    public function show(Request $request, Task $task): TaskDetailResource
    {
        $this->authorize('view', $task);

        $user = $request->user();

        return new TaskDetailResource($this->taskService->detail(
            $task,
            $user?->id,
            // A reviewer opens a proposal to edit it before approving, so they
            // need the whole bundle — including its still-pending subtasks.
            (bool) $user?->can('projects.tasks.approve'),
        ));
    }

    public function update(UpdateTaskRequest $request, Task $task): TaskResource
    {
        $this->authorize('update', $task);

        $data = $request->validated();

        // RBAC: Admin/PM may edit the full task, and so may a member still
        // revising their own unapproved proposal. Everyone else who reaches this
        // point (e.g. the assignee) may change the status only.
        $user = $request->user();
        $isManager = $user->hasAnyRole(UserRole::managerRoles());
        $isOwnProposal = $task->isEditableProposalFor($user->id);

        if (! $isManager && ! $isOwnProposal) {
            $data = array_intersect_key($data, array_flip(['status_id']));
        }

        // A proposal has no status progression — it isn't on a board yet. Approve
        // it first (the reviewer may still set its starting status while editing).
        if ($task->isProposal() && ! $isManager && array_key_exists('status_id', $data)) {
            throw ValidationException::withMessages([
                'status_id' => 'This task is still awaiting approval, so its status cannot be changed yet.',
            ]);
        }

        // Track whether this update transitions the task INTO the done status,
        // so we can fire the completion celebration exactly once.
        $wasDone = $task->loadMissing('status')->status?->key === StatusKey::DONE;
        $wasProposal = $task->isProposal();

        $task = $this->taskService->update($task, $data, $user);
        $task->load('project', 'status', 'group', 'assignee', 'assignedBy', 'submittedBy');

        // A proposal is only visible to its author and the review queue, so
        // editing one is not a project-wide event.
        if (! $wasProposal) {
            SafeBroadcast::toOthers(new TaskUpdated($task));

            if (! $wasDone && $task->status?->key === StatusKey::DONE) {
                SafeBroadcast::toOthers(new TaskCompleted($task));
            }
        }

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

        SafeBroadcast::toOthers(new TaskDeleted($taskUuid, $projectUuid));

        return response()->noContent();
    }
}
