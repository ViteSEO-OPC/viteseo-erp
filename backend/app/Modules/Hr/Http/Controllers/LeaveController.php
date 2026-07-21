<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\CancelLeaveRequest;
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

    // GET /api/v1/hr/leave-types — any authenticated user (needed by the form).
    public function leaveTypes(): JsonResponse
    {
        return LeaveTypeResource::collection(LeaveType::orderBy('name')->get())->response();
    }

    // GET /api/v1/hr/me/leave-balances — the caller's balances for this year.
    public function myBalances(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(['data' => []]);
        }

        $balances = $this->service->balancesForCurrentYear($employee);

        return LeaveBalanceResource::collection($balances)->response();
    }

    // GET /api/v1/hr/leave-requests — HR sees all; employees see their own.
    public function index(Request $request): JsonResponse
    {
        $requests = $this->service->listRequests($request->user());

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
}
