<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Events\CommentCreated;
use App\Modules\Projects\Events\CommentDeleted;
use App\Modules\Projects\Models\Comment;
use App\Modules\Projects\Models\Subtask;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Requests\StoreCommentRequest;
use App\Modules\Projects\Resources\CommentResource;
use App\Modules\Projects\Services\CommentService;
use App\Modules\Projects\Support\SafeBroadcast;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CommentController extends Controller
{
    public function __construct(private readonly CommentService $commentService) {}

    public function taskIndex(Request $request, Task $task): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Comment::class);

        $comments = $task->comments()
            ->with('author')
            ->latest()
            ->paginate((int) $request->input('per_page', 15));

        return CommentResource::collection($comments);
    }

    public function taskStore(StoreCommentRequest $request, Task $task): CommentResource
    {
        $this->authorize('create', Comment::class);

        $comment = $this->commentService->createForTask($task, $request->validated(), $request->user());

        $task->loadMissing('project');
        SafeBroadcast::toOthers(new CommentCreated(
            $comment,
            (string) $task->project->uuid,
            (string) $task->uuid,
        ));

        return new CommentResource($comment->load('author'));
    }

    public function subtaskIndex(Request $request, Subtask $subtask): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Comment::class);

        $comments = $subtask->comments()
            ->with('author')
            ->latest()
            ->paginate((int) $request->input('per_page', 15));

        return CommentResource::collection($comments);
    }

    public function subtaskStore(StoreCommentRequest $request, Subtask $subtask): CommentResource
    {
        $this->authorize('create', Comment::class);

        $comment = $this->commentService->createForSubtask($subtask, $request->validated(), $request->user());

        $subtask->loadMissing('task.project');
        SafeBroadcast::toOthers(new CommentCreated(
            $comment,
            (string) $subtask->task->project->uuid,
            (string) $subtask->task->uuid,
        ));

        return new CommentResource($comment->load('author'));
    }

    public function destroy(Comment $comment): Response
    {
        $this->authorize('delete', $comment);

        // Resolve the project + parent task/subtask uuids before deleting so the
        // broadcast payload doesn't depend on the (hard-deleted) comment row.
        $comment->loadMissing('task.project', 'subtask.task.project');
        $commentUuid = (string) $comment->uuid;

        if ($comment->task_id !== null) {
            $projectUuid = (string) $comment->task->project->uuid;
            $taskUuid = (string) $comment->task->uuid;
            $subtaskUuid = null;
        } else {
            $projectUuid = (string) $comment->subtask->task->project->uuid;
            $taskUuid = (string) $comment->subtask->task->uuid;
            $subtaskUuid = (string) $comment->subtask->uuid;
        }

        $this->commentService->delete($comment);

        SafeBroadcast::toOthers(new CommentDeleted($commentUuid, $projectUuid, $taskUuid, $subtaskUuid));

        return response()->noContent();
    }
}
