<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Models\Link;
use App\Modules\Projects\Models\Subtask;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Requests\StoreLinkRequest;
use App\Modules\Projects\Resources\LinkResource;
use App\Modules\Projects\Services\LinkService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class LinkController extends Controller
{
    public function __construct(private readonly LinkService $linkService) {}

    public function taskIndex(Request $request, Task $task): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Link::class);

        $links = $task->links()
            ->with('user')
            ->latest()
            ->paginate((int) $request->input('per_page', 15));

        return LinkResource::collection($links);
    }

    public function taskStore(StoreLinkRequest $request, Task $task): LinkResource
    {
        $this->authorize('create', Link::class);

        $link = $this->linkService->createForTask($task, $request->validated(), $request->user());

        return new LinkResource($link->load('user'));
    }

    public function subtaskIndex(Request $request, Subtask $subtask): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Link::class);

        $links = $subtask->links()
            ->with('user')
            ->latest()
            ->paginate((int) $request->input('per_page', 15));

        return LinkResource::collection($links);
    }

    public function subtaskStore(StoreLinkRequest $request, Subtask $subtask): LinkResource
    {
        $this->authorize('create', Link::class);

        $link = $this->linkService->createForSubtask($subtask, $request->validated(), $request->user());

        return new LinkResource($link->load('user'));
    }

    public function destroy(Link $link): Response
    {
        $this->authorize('delete', $link);

        $this->linkService->delete($link);

        return response()->noContent();
    }
}
