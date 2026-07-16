<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Projects\Models\Group;
use App\Modules\Projects\Services\WorkloadService;
use Illuminate\Http\JsonResponse;

class WorkloadController extends Controller
{
    public function __construct(private readonly WorkloadService $workload) {}

    /**
     * The selected member's live active workload, loaded on demand during task
     * assignment. Advisory only — never blocks an assignment.
     */
    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => $this->workload->summary($user)]);
    }

    /**
     * A group's live aggregated workload, shown when a task is assigned to a
     * group. Advisory only — never blocks an assignment.
     */
    public function showGroup(Group $group): JsonResponse
    {
        return response()->json(['data' => $this->workload->groupSummary($group)]);
    }
}
