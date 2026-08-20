<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\ReopenRequest;
use App\Modules\Hr\Http\Requests\StoreOvertimeRequestRequest;
use App\Modules\Hr\Http\Requests\StoreRetroactiveOvertimeRequest;
use App\Modules\Hr\Http\Resources\OvertimeRequestResource;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\OvertimeRequest;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\OvertimeRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OvertimeRequestController extends Controller
{
    public function __construct(private readonly OvertimeRequestService $service) {}

    // GET /api/v1/hr/overtime-requests — HR sees all; employees see their own.
    public function index(Request $request): JsonResponse
    {
        return OvertimeRequestResource::collection($this->service->list($request->user()))->response();
    }

    // GET /api/v1/hr/overtime-requests/settings — whether overtime can be REQUESTED at all, so the
    // form can explain itself when the company runs overtime off or fully automatic.
    public function settings(): JsonResponse
    {
        return response()->json(['data' => $this->service->settings()]);
    }

    /**
     * GET /api/v1/hr/overtime-requests/auto-review?start=&end= — auto-mode overtime the caps held
     * back. HR only, and read-only: the list is derived from attendance every time, so it
     * writes nothing and re-running payroll cannot change it.
     */
    public function autoReview(Request $request, AttendanceService $attendance): JsonResponse
    {
        $data = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after_or_equal:start'],
        ]);

        return response()->json([
            'data' => $attendance->autoOvertimeReview($data['start'], $data['end']),
        ]);
    }

    // GET /api/v1/hr/overtime-requests/classify?date=YYYY-MM-DD — how that date's overtime would be
    // classified for the CALLER (regular / rest day / special day / regular holiday) + its rate, so
    // the filing form can show it live. Display only; payroll re-derives the rate at run time.
    public function classify(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date']]);
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(['message' => 'Only employees can file an overtime request.'], Response::HTTP_FORBIDDEN);
        }

        return response()->json(['data' => $this->service->classify($employee, $data['date'])]);
    }

    // POST /api/v1/hr/overtime-requests — the caller files their own overtime request.
    public function store(StoreOvertimeRequestRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(
                ['message' => 'Only employees can file an overtime request.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        return OvertimeRequestResource::make($this->service->create($employee, $request->validated()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * POST /api/v1/hr/overtime-requests/for-employee — HR files overtime for someone, after the
     * fact (item 10). HR only. Created PENDING, so the ordinary approve flow still rules on it.
     */
    public function storeForEmployee(StoreRetroactiveOvertimeRequest $request): JsonResponse
    {
        $employee = Employee::findOrFail($request->validated('employee_id'));

        return OvertimeRequestResource::make(
            $this->service->createFor($employee, $request->validated(), $request->user()),
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    // POST /api/v1/hr/overtime-requests/{overtimeRequest}/approve — HR only.
    public function approve(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        return OvertimeRequestResource::make($this->service->approve($overtimeRequest, $request->user()))->response();
    }

    // POST /api/v1/hr/overtime-requests/{overtimeRequest}/reject — HR only.
    public function reject(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return OvertimeRequestResource::make(
            $this->service->reject($overtimeRequest, $request->user(), $data['reason']),
        )->response();
    }

    // POST /api/v1/hr/overtime-requests/{overtimeRequest}/cancel — HR only.
    // Undoes a request that was already approved but whose overtime is not happening.
    public function cancel(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return OvertimeRequestResource::make(
            $this->service->cancel($overtimeRequest, $request->user(), $data['reason']),
        )->response();
    }

    // POST /api/v1/hr/overtime-requests/{overtimeRequest}/reopen — HR only: undo the
    // decision and put the request back in the queue (cancel() ends it instead).
    public function reopen(ReopenRequest $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        return OvertimeRequestResource::make(
            $this->service->reopen($overtimeRequest, $request->validated('reason')),
        )->response();
    }
}
