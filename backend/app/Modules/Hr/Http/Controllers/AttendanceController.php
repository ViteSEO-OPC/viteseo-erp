<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\AdjustAttendanceRequest;
use App\Modules\Hr\Http\Requests\ClockInRequest;
use App\Modules\Hr\Http\Resources\AttendanceResource;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\MyAttendanceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $service,
        private readonly MyAttendanceReportService $myReport,
    ) {}

    // GET /api/v1/hr/me/attendance — today's row (for the clock panel) + this week.
    public function me(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json([
                'data' => [
                    'today' => null,
                    'week' => [],
                    // Shape must match clockInWindowFor()'s — a caller with no employee record
                    // reads this branch, and a short object here means the panel's optional
                    // chaining silently decides the rules instead of the server.
                    'clock_in' => [
                        'mode' => 'allow',
                        'opens_at' => null,
                        'defers_to' => null,
                        'geofence' => false,
                        'is_workday' => true,
                        'work_request_required' => null,
                        'work_request' => null,
                        'suspension' => null,
                    ],
                    // Same reason as clock_in above: the shape must match the real branch's, or
                    // the panel's optional chaining ends up deciding the day's rules.
                    'day_rules' => null,
                ],
            ]);
        }

        $today = $this->service->todayFor($employee);

        return response()->json([
            'data' => [
                'today' => $today ? AttendanceResource::make($today) : null,
                'week' => AttendanceResource::collection($this->service->currentWeek($employee)),
                // Lets the panel disable "Time in" and say when it opens, instead of the
                // employee discovering the strict rule through a rejected press.
                'clock_in' => $this->service->clockInWindowFor($employee),
                // Today's declared rules, so the panel can say "full day at 6h — you reach it
                // at 3:12 PM" instead of leaving the employee to discover it on their payslip.
                'day_rules' => $this->service->dayRulesFor($employee),
            ],
        ]);
    }

    /**
     * GET /api/v1/hr/me/attendance-report?month=YYYY-MM — the caller's OWN month, day by day,
     * with the twelve-month context around it.
     *
     * The employee is resolved from the token, never from the query string: this returns one
     * person's punches, lateness and leave, and a client-supplied id would make it everyone's.
     */
    public function myReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // A malformed month is not an error worth a red toast — the service falls back to the
            // current one — but a month that is not a month should never reach the date parser.
            'month' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(
                ['message' => 'Only employees have an attendance record.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        return response()->json(['data' => $this->myReport->build($employee, $validated)]);
    }

    public function clockIn(ClockInRequest $request): JsonResponse
    {
        return $this->clockAction($request, fn ($emp) => $this->service->clockIn(
            $emp,
            $request->filled('lat') ? (float) $request->validated('lat') : null,
            $request->filled('lng') ? (float) $request->validated('lng') : null,
            $request->filled('accuracy') ? (float) $request->validated('accuracy') : null,
            // From the SERVER's view of the connection, never the body — a client-supplied
            // address would make the whole network check self-certified.
            $request->ip(),
        ));
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

    /**
     * GET /api/v1/hr/attendance/open-shifts?from=&to= — the stale-attendance review queue (item 6).
     *
     * Shifts with a punch in and none out, split into the ones auto-closure could not price
     * (`needs_fix`, the pay is wrong until HR corrects it) and the ones it could (`auto_closed`,
     * tidy-up only). Corrections go back through `attendance/adjust` below — this only finds them.
     */
    public function openShifts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            // No `after_or_equal:from`: an inverted range returns nothing rather than erroring,
            // which is what a date-picker mid-edit produces and is not worth a red toast.
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json([
            'data' => $this->service->openShiftReview($data['from'] ?? null, $data['to'] ?? null),
        ]);
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
