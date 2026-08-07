<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Events\SubtaskUpdated;
use App\Modules\Projects\Events\TaskCreated;
use App\Modules\Projects\Models\Subtask;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Requests\RejectApprovalRequest;
use App\Modules\Projects\Resources\PendingSubtaskResource;
use App\Modules\Projects\Resources\PendingTaskResource;
use App\Modules\Projects\Resources\SubtaskResource;
use App\Modules\Projects\Resources\TaskResource;
use App\Modules\Projects\Services\ApprovalService;
use App\Support\SafeBroadcast;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The review surface for member-submitted work: list what is waiting, then
 * approve or reject it. Reviewers EDIT a proposal through the ordinary task /
 * subtask update endpoints — there is no separate edit path here.
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function tasks(Request $request): AnonymousResourceCollection
    {
        $this->authorize('approve', Task::class);

        return PendingTaskResource::collection($this->approvals->tasks(
            $request->only(['state', 'search']),
            (int) $request->input('per_page', 15),
        ));
    }

    public function subtasks(Request $request): AnonymousResourceCollection
    {
        $this->authorize('approve', Subtask::class);

        return PendingSubtaskResource::collection($this->approvals->subtasks(
            $request->only(['state', 'search']),
            (int) $request->input('per_page', 15),
        ));
    }

    public function approveTask(Request $request, Task $task): TaskResource
    {
        $this->authorize('approve', $task);

        $task = $this->approvals->approveTask($task, $request->user());
        $task->load('project', 'status', 'group', 'assignee', 'assignedBy', 'submittedBy');

        // Now that it is real work, announce it like any newly created task so
        // every open board picks it up.
        SafeBroadcast::toOthers(new TaskCreated($task));

        return new TaskResource($task);
    }

    public function rejectTask(RejectApprovalRequest $request, Task $task): TaskResource
    {
        $this->authorize('approve', $task);

        $task = $this->approvals->rejectTask($task, $request->user(), $request->validated()['reason']);
        $task->load('project', 'status', 'group', 'assignee', 'assignedBy', 'submittedBy');

        return new TaskResource($task);
    }

    public function approveSubtask(Request $request, Subtask $subtask): SubtaskResource
    {
        $this->authorize('approve', $subtask);

        $subtask = $this->approvals->approveSubtask($subtask, $request->user());
        $subtask->load('task.project', 'assignee', 'status', 'submittedBy');

        SafeBroadcast::toOthers(new SubtaskUpdated(
            $subtask,
            (string) $subtask->task->project->uuid,
            (string) $subtask->task->uuid,
        ));

        return new SubtaskResource($subtask);
    }

    public function rejectSubtask(RejectApprovalRequest $request, Subtask $subtask): SubtaskResource
    {
        $this->authorize('approve', $subtask);

        $subtask = $this->approvals->rejectSubtask($subtask, $request->user(), $request->validated()['reason']);
        $subtask->load('task', 'assignee', 'status', 'submittedBy');

        return new SubtaskResource($subtask);
    }

    /**
     * The author sends a revised proposal back for review. Not a manager action —
     * the policy allows only the submitter, and only while it is unapproved.
     */
    public function resubmitTask(Request $request, Task $task): TaskResource
    {
        $this->authorize('resubmit', $task);

        $task = $this->approvals->resubmitTask($task, $request->user());
        $task->load('project', 'status', 'group', 'assignee', 'assignedBy', 'submittedBy');

        return new TaskResource($task);
    }

    public function resubmitSubtask(Request $request, Subtask $subtask): SubtaskResource
    {
        $this->authorize('resubmit', $subtask);

        $subtask = $this->approvals->resubmitSubtask($subtask, $request->user());
        $subtask->load('task', 'assignee', 'status', 'submittedBy');

        return new SubtaskResource($subtask);
    }
}
