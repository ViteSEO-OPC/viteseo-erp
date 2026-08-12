<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Enums\UserRole;
use App\Modules\Projects\Models\WorkloadSetting;
use App\Modules\Projects\Requests\UpdateWorkloadCapacityRequest;
use App\Modules\Projects\Services\WorkloadAnalysisService;
use App\Modules\Projects\Services\WorkloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Workload Analysis page.
 *
 * Everyone may open it; what comes back is scoped by role inside
 * {@see WorkloadAnalysisService::rows()} — a manager gets the whole platform, a
 * member gets only their own work. Retuning capacity is manager-only.
 */
class WorkloadAnalysisController extends Controller
{
    public function __construct(
        private readonly WorkloadAnalysisService $analysis,
        private readonly WorkloadService $workload,
    ) {}

    /**
     * KPI cards, chart series and the current capacity bands.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->only(['project_id', 'group_id', 'assignee_id', 'category', 'priority', 'search']);

        $rows = $this->analysis->rows($user, $filters);
        $isManager = $user->hasAnyRole(UserRole::managerRoles());

        $data = [
            'totals' => $this->analysis->totals($rows),
            'thresholds' => $this->workload->thresholds(),
            'can_adjust_capacity' => (bool) $user->can('projects.workload.manage_capacity'),
        ];

        if ($isManager) {
            $data['members'] = $this->analysis->byMember($rows);
            $data['groups'] = $this->analysis->byGroup($rows);
        } else {
            // The member page shows one workload (their own) plus a completion
            // panel that — unlike everything else here — does count Done work.
            $mine = $this->analysis->byMember($rows);
            $data['me'] = $mine[0] ?? [
                'id' => $user->id,
                'name' => $user->name,
                'light' => 0, 'moderate' => 0, 'heavy' => 0,
                'total_effort' => 0, 'task_count' => 0,
                'level' => $this->workload->level(0),
            ];
            $data['completion'] = $this->analysis->completionFor($user);
        }

        return response()->json(['data' => $data]);
    }

    /**
     * The task table: every active work row, flattened across tasks and
     * sub-tasks, paginated.
     */
    public function tasks(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->only(['project_id', 'group_id', 'assignee_id', 'category', 'priority', 'search']);

        $rows = $this->analysis->rows($user, $filters);

        $paginator = $this->analysis->paginate(
            $rows,
            (int) $request->input('per_page', 10),
            max(1, (int) $request->input('page', 1)),
            $request->input('sort'),
        );

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'links' => [],
        ]);
    }

    /**
     * Retune the platform-wide bands. Manager-only: this changes what "heavy"
     * means for every member, on every board.
     */
    public function updateCapacity(UpdateWorkloadCapacityRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('projects.workload.manage_capacity'), 403);

        $setting = WorkloadSetting::current();
        $setting->fill($request->validated());
        $setting->updated_by_id = $request->user()->id;
        $setting->save();

        return response()->json([
            'data' => [
                'light' => $setting->light_max,
                'moderate' => $setting->moderate_max,
                'max' => $setting->max_capacity,
            ],
        ]);
    }
}
