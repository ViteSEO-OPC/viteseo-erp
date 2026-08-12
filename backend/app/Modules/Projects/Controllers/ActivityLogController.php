<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Models\ActivityLog;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Resources\ActivityFeedResource;
use App\Modules\Projects\Resources\ActivityLogResource;
use App\Modules\Projects\Services\ActivityFeedService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityLogController extends Controller
{
    public function __construct(
        private readonly ActivityFeedService $feed,
    ) {}

    /**
     * The Recent Activity feed: task and sub-task events across every project
     * the caller can see, newest first. Optionally scoped to one workspace or
     * project via `filter[workspace_id]` / `filter[project_id]`.
     */
    public function feed(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ActivityLog::class);

        $logs = $this->feed->paginate(
            $request->user(),
            (array) $request->input('filter', []),
            (int) $request->input('per_page', 15),
        );

        return ActivityFeedResource::collection($logs);
    }

    public function index(Request $request, Task $task): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ActivityLog::class);

        $logs = $task->activityLogs()
            ->with('user')
            ->latest()
            ->paginate((int) $request->input('per_page', 15));

        return ActivityLogResource::collection($logs);
    }
}
