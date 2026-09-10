<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\CancelLeaveRequest;
use App\Modules\Hr\Http\Requests\ReopenRequest;
use App\Modules\Hr\Http\Requests\StoreLeaveRequest;
use App\Modules\Hr\Http\Resources\LeaveBalanceResource;
use App\Modules\Hr\Http\Resources\LeaveRequestResource;
use App\Modules\Hr\Http\Resources\LeaveTypeResource;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Services\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LeaveController extends Controller
{
    public function __construct(private readonly LeaveService $service) {}

    /**
     * GET /api/v1/hr/leave-types — any authenticated user (the filing form needs it).
     *
     * @see LeaveService::selectableTypesFor() for the rule, including why unpaid leave always shows.
     */
    public function leaveTypes(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasAnyRole(['hr_officer', 'super_admin']) || $user->employee === null) {
            return LeaveTypeResource::collection(LeaveType::orderBy('name')->get())->response();
        }

        return LeaveTypeResource::collection(
            $this->service->selectableTypesFor($user->employee),
        )->response();
    }

    // GET /api/v1/hr/me/leave-balances — the caller's balances for this year.
    public function myBalances(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json([
                'data' => [],
                'meta' => ['mode' => 'not_shared', 'pool' => null],
            ]);
        }

        $summary = $this->service->summaryForCurrentYear($employee);

        return LeaveBalanceResource::collection($summary['balances'])
            ->additional(['meta' => [
                'mode' => $summary['mode'],
                'pool' => $summary['pool'],
            ]])
            ->response();
    }

    // GET /api/v1/hr/leave-requests — HR sees all; employees see their own.
    public function index(Request $request): JsonResponse
    {
        // Applied by the DATABASE. In the browser they would only narrow the page on screen.
        $requests = $this->service->listRequests(
            $request->user(),
            $request->only(['search', 'status', 'per_page', 'from', 'to']),
        );

        return LeaveRequestResource::collection($requests)->response();
    }

    // POST /api/v1/hr/leave-requests — the caller files their own leave.
    public function store(StoreLeaveRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(
                ['message' => 'Only employees can file leave.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $leaveRequest = $this->service->create($employee, $request->validated());

        return LeaveRequestResource::make($leaveRequest)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    // POST /api/v1/hr/leave-requests/{leaveRequest}/cancel — employee (own,
    // pending) or HR (pending/approved). Rules enforced in the service.
    public function cancel(CancelLeaveRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $updated = $this->service->cancel(
            $leaveRequest,
            $request->user(),
            $request->validated()['reason'],
        );

        return LeaveRequestResource::make($updated)->response();
    }

    // POST /api/v1/hr/leave-requests/{leaveRequest}/approve — HR only.
    public function approve(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $updated = $this->service->approve($leaveRequest, $request->user());

        return LeaveRequestResource::make($updated)->response();
    }

    // POST /api/v1/hr/leave-requests/{leaveRequest}/reject — HR only.
    public function reject(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $updated = $this->service->reject($leaveRequest, $request->user(), $data['reason']);

        return LeaveRequestResource::make($updated)->response();
    }

    // POST /api/v1/hr/leave-requests/{leaveRequest}/reopen — HR only: undo the decision.
    public function reopen(ReopenRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $updated = $this->service->reopen($leaveRequest, $request->validated('reason'));

        return LeaveRequestResource::make($updated)->response();
    }
}
