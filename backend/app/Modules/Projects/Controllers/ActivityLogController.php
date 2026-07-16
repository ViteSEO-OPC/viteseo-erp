<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Models\ActivityLog;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Resources\ActivityLogResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityLogController extends Controller
{
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
