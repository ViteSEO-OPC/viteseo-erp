<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\AdjustAttendanceRequest;
use App\Modules\Hr\Http\Resources\AttendanceResource;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $service) {}

    // GET /api/v1/hr/me/attendance — today's row (for the clock panel) + this week.
    public function me(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(['data' => ['today' => null, 'week' => []]]);
        }

        $today = $this->service->todayFor($employee);

        return response()->json([
            'data' => [
                'today' => $today ? AttendanceResource::make($today) : null,
                'week' => AttendanceResource::collection($this->service->currentWeek($employee)),
            ],
        ]);
    }

    public function clockIn(Request $request): JsonResponse
    {
        return $this->clockAction($request, fn ($emp) => $this->service->clockIn($emp));
    }

    public function clockOut(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tasks' => ['nullable', 'array', 'max:20'],
            'tasks.*.text' => ['required_with:tasks', 'string', 'max:255'],
            'tasks.*.done' => ['boolean'],
        ]);

        return $this->clockAction(
            $request,
            fn ($emp) => $this->service->clockOut($emp, $validated['tasks'] ?? []),
        );
    }

    public function break(Request $request): JsonResponse
    {
        $validated = $request->validate(['type' => ['required', 'in:lunch,break']]);

        return $this->clockAction(
            $request,
            fn ($emp) => $this->service->toggleBreak($emp, $validated['type']),
        );
    }

    // GET /api/v1/hr/attendance?date=YYYY-MM-DD — HR roster for a day.
    public function index(Request $request): JsonResponse
    {
        $date = $request->query('date');

        return response()->json(['data' => $this->service->allForDate($date)]);
    }

    // GET /api/v1/hr/attendance/summary?date=YYYY-MM-DD — the 8 day tiles.
    public function summary(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->summary($request->query('date'))]);
    }

    // POST /api/v1/hr/attendance/adjust — HR sets/corrects a row (HR only).
    public function adjust(AdjustAttendanceRequest $request): JsonResponse
    {
        $employee = Employee::findOrFail($request->validated('employee_id'));

        $attendance = $this->service->adjust(
            $employee,
            $request->validated('work_date'),
            $request->validated(),
        );

        return AttendanceResource::make($attendance)->response();
    }

    /** Shared: resolve the caller's employee, run the clock action, return it. */
    private function clockAction(Request $request, callable $action): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(
                ['message' => 'Only employees can use the time clock.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        return AttendanceResource::make($action($employee))->response();
    }
}
