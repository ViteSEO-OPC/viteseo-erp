<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StoreWorkScheduleRequest;
use App\Modules\Hr\Http\Requests\UpdateEmployeeScheduleRequest;
use App\Modules\Hr\Http\Resources\WorkScheduleResource;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\ScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

// Company → Rules (schedules). All routes are hr_officer-only.
class WorkScheduleController extends Controller
{
    public function __construct(private readonly ScheduleService $service) {}

    // GET /hr/work-schedules
    public function index(): JsonResponse
    {
        return WorkScheduleResource::collection($this->service->list())->response();
    }

    // POST /hr/work-schedules
    public function store(StoreWorkScheduleRequest $request): JsonResponse
    {
        $schedule = $this->service->create($request->validated());

        return WorkScheduleResource::make($schedule)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    // PUT/PATCH /hr/work-schedules/{workSchedule}
    public function update(StoreWorkScheduleRequest $request, WorkSchedule $workSchedule): JsonResponse
    {
        $schedule = $this->service->update($workSchedule, $request->validated());

        return WorkScheduleResource::make($schedule)->response();
    }

    // DELETE /hr/work-schedules/{workSchedule}
    public function destroy(WorkSchedule $workSchedule): JsonResponse|Response
    {
        try {
            $this->service->delete($workSchedule);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->noContent();
    }

    // GET /hr/employees/{employee}/schedule
    public function employeeSchedule(Employee $employee): JsonResponse
    {
        return response()->json(['data' => $this->schedulePayload($employee)]);
    }

    // PUT/PATCH /hr/employees/{employee}/schedule
    public function updateEmployeeSchedule(UpdateEmployeeScheduleRequest $request, Employee $employee): JsonResponse
    {
        $employee = $this->service->assignForEmployee($employee, $request->validated());

        return response()->json(['data' => $this->schedulePayload($employee)]);
    }

    /**
     * The employee's raw assignment + overrides + the resolved effective schedule.
     *
     * @return array<string, mixed>
     */
    private function schedulePayload(Employee $employee): array
    {
        return [
            'work_schedule_id' => $employee->work_schedule_id,
            'custom_workdays' => $employee->custom_workdays,
            'custom_start_time' => $employee->custom_start_time ? substr($employee->custom_start_time, 0, 5) : null,
            'custom_end_time' => $employee->custom_end_time ? substr($employee->custom_end_time, 0, 5) : null,
            'custom_am_start_time' => $employee->custom_am_start_time ? substr($employee->custom_am_start_time, 0, 5) : null,
            'custom_am_end_time' => $employee->custom_am_end_time ? substr($employee->custom_am_end_time, 0, 5) : null,
            'custom_pm_start_time' => $employee->custom_pm_start_time ? substr($employee->custom_pm_start_time, 0, 5) : null,
            'custom_pm_end_time' => $employee->custom_pm_end_time ? substr($employee->custom_pm_end_time, 0, 5) : null,
            'custom_grace_minutes' => $employee->custom_grace_minutes,
            'effective' => $this->service->effectiveFor($employee),
        ];
    }
}
