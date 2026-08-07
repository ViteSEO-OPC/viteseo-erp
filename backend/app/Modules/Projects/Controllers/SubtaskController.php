<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Enums\ApprovalStatus;
use App\Modules\Projects\Enums\AssignmentType;
use App\Modules\Projects\Enums\UserRole;
use App\Modules\Projects\Events\SubtaskUpdated;
use App\Modules\Projects\Models\Subtask;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Requests\StoreSubtaskRequest;
use App\Modules\Projects\Requests\UpdateSubtaskRequest;
use App\Modules\Projects\Resources\SubtaskResource;
use App\Modules\Projects\Services\NotificationService;
use App\Modules\Projects\Services\SubtaskService;
use App\Support\SafeBroadcast;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class SubtaskController extends Controller
{
    public function __construct(
        private readonly SubtaskService $subtaskService,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request, Task $task): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Subtask::class);

        $user = $request->user();

        $subtasks = $task->subtasks()
            // Unapproved proposals stay hidden from everyone but their author —
            // and a reviewer, who needs them to act on the queue.
            ->when(
                ! $user?->can('projects.subtasks.approve'),
                fn ($q) => $q->visibleTo($user?->id),
            )
            ->with(['assignee', 'status', 'submittedBy'])
            ->orderBy('created_at')
            ->paginate((int) $request->input('per_page', 15));

        return SubtaskResource::collection($subtasks);
    }

    public function store(StoreSubtaskRequest $request, Task $task): SubtaskResource
    {
        // Subtasks may be created by Admin/PM (permission) or, on a group-assigned
        // task, by that group's leader (the derived co-assignee, per the spec).
        $user = $request->user();
        $task->loadMissing('group.members');
        $isGroupLeader = $task->assignment_type === AssignmentType::GROUP
            && $task->group?->leader_id === $user->id;

        $canCreateDirectly = $user->can('projects.subtasks.create') || $isGroupLeader;

        // A member may PROPOSE a subtask on work that is already theirs: a task
        // assigned to them, a group task of a group they belong to, or their own
        // task proposal (in which case it joins that proposal's bundle).
        $ownsTheWork = (int) $task->assignee_id === (int) $user->id
            || (int) $task->submitted_by_id === (int) $user->id
            || ($task->assignment_type === AssignmentType::GROUP
                && $task->group?->members->contains('id', $user->id));
        $canPropose = $user->can('projects.subtasks.propose') && $ownsTheWork;

        abort_unless($canCreateDirectly || $canPropose, 403);

        $data = $request->validated();

        // Proposal path: always self-assigned, always pending review.
        $isProposal = ! $canCreateDirectly;
        if ($isProposal) {
            $data['assignee_id'] = $user->id;
            $data['approval_status'] = ApprovalStatus::PENDING;
            $data['submitted_by_id'] = $user->id;
        }

        $subtask = $this->subtaskService->create($task, $data, $user);
        $subtask->load('task', 'assignee', 'status', 'submittedBy');

        // A subtask proposed under the author's own pending task is part of that
        // bundle and is reviewed with it — only a standalone proposal (one on an
        // already-approved task) raises its own review request.
        if ($isProposal && ! $task->isProposal()) {
            $this->notifications->notifySubtaskReviewRequested($subtask, $user);
        }

        return new SubtaskResource($subtask);
    }

    public function show(Subtask $subtask): SubtaskResource
    {
        $this->authorize('view', $subtask);

        return new SubtaskResource($subtask->load('task', 'assignee', 'status', 'submittedBy'));
    }

    public function update(UpdateSubtaskRequest $request, Subtask $subtask): SubtaskResource
    {
        $this->authorize('update', $subtask);

        $user = $request->user();
        $data = $request->validated();

        // RBAC: Admin/PM and the parent group's leader may edit the full subtask,
        // and so may a member still revising their own unapproved proposal.
        // Everyone else who reaches this point (the subtask's assignee) may change
        // the status only.
        $isManager = $user->hasAnyRole(UserRole::managerRoles());
        $subtask->loadMissing('task.group');
        $isGroupLeader = $subtask->task?->assignment_type === AssignmentType::GROUP
            && $subtask->task->group?->leader_id === $user->id;
        $isOwnProposal = $subtask->isEditableProposalFor($user->id);

        if (! $isManager && ! $isGroupLeader && ! $isOwnProposal) {
            $data = array_intersect_key($data, array_flip(['status_id']));
        }

        // A proposal isn't on a board yet, so it has no status to advance.
        if ($subtask->isProposal() && ! $isManager && array_key_exists('status_id', $data)) {
            throw ValidationException::withMessages([
                'status_id' => 'This sub-task is still awaiting approval, so its status cannot be changed yet.',
            ]);
        }

        $wasProposal = $subtask->isProposal();

        $subtask = $this->subtaskService->update($subtask, $data, $user);
        $subtask->load('task', 'assignee', 'status', 'submittedBy');
        $subtask->task->loadMissing('project');

        // Editing a proposal is not a project-wide event — it broadcasts on approval.
        if (! $wasProposal) {
            SafeBroadcast::toOthers(new SubtaskUpdated(
                $subtask,
                (string) $subtask->task->project->uuid,
                (string) $subtask->task->uuid,
            ));
        }

        return new SubtaskResource($subtask);
    }

    public function destroy(Subtask $subtask): Response
    {
        $this->authorize('delete', $subtask);

        $this->subtaskService->delete($subtask);

        return response()->noContent();
    }
}
