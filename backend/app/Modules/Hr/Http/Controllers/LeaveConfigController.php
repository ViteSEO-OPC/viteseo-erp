<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StoreLeaveTypeAllotmentRequest;
use App\Modules\Hr\Http\Requests\StoreLeaveTypeRequest;
use App\Modules\Hr\Http\Requests\StoreSharedLeaveAllotmentRequest;
use App\Modules\Hr\Http\Requests\UpdateLeaveSettingRequest;
use App\Modules\Hr\Http\Requests\UpdateLeaveTypeRequest;
use App\Modules\Hr\Http\Resources\LeaveBalanceResource;
use App\Modules\Hr\Http\Resources\LeaveSettingResource;
use App\Modules\Hr\Http\Resources\LeaveTypeAllotmentResource;
use App\Modules\Hr\Http\Resources\LeaveTypeResource;
use App\Modules\Hr\Http\Resources\SharedLeaveAllotmentResource;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\LeaveTypeAllotment;
use App\Modules\Hr\Services\LeaveConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// Company → Leave Type. All routes are hr_officer-only (see Routes/api.php).
class LeaveConfigController extends Controller
{
    public function __construct(private readonly LeaveConfigService $service) {}

    // --- Leave types ---

    // POST /hr/leave-types
    public function storeType(StoreLeaveTypeRequest $request): JsonResponse
    {
        $type = $this->service->createType($request->validated());

        return LeaveTypeResource::make($type)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    // PUT/PATCH /hr/leave-types/{leaveType}
    public function updateType(UpdateLeaveTypeRequest $request, LeaveType $leaveType): JsonResponse
    {
        $type = $this->service->updateType($leaveType, $request->validated());

        return LeaveTypeResource::make($type)->response();
    }

    // DELETE /hr/leave-types/{leaveType}
    public function destroyType(LeaveType $leaveType): Response
    {
        $this->service->deleteType($leaveType);

        return response()->noContent();
    }

    // --- Global allotment mode (shared vs not-shared) ---

    // GET /hr/leave-settings
    public function settingsShow(): JsonResponse
    {
        return LeaveSettingResource::make($this->service->getSettings())->response();
    }

    // PUT/PATCH /hr/leave-settings
    public function settingsUpdate(UpdateLeaveSettingRequest $request): JsonResponse
    {
        $settings = $this->service->setMode($request->validated()['allotment_mode']);

        return LeaveSettingResource::make($settings)->response();
    }

    // --- Shared allotment defaults (one pool per employment status) ---

    // GET /hr/shared-leave-allotments
    public function sharedAllotmentsIndex(): JsonResponse
    {
        return SharedLeaveAllotmentResource::collection($this->service->listSharedAllotments())->response();
    }

    // POST /hr/shared-leave-allotments — upsert one status's shared pool default.
    public function storeSharedAllotment(StoreSharedLeaveAllotmentRequest $request): JsonResponse
    {
        $allotment = $this->service->upsertSharedAllotment($request->validated());

        return SharedLeaveAllotmentResource::make($allotment)->response();
    }

    // --- Allotment defaults ---

    // GET /hr/leave-type-allotments
    public function allotmentsIndex(): JsonResponse
    {
        return LeaveTypeAllotmentResource::collection($this->service->listAllotments())->response();
    }

    // POST /hr/leave-type-allotments — upsert one (leave type + status) default.
    public function storeAllotment(StoreLeaveTypeAllotmentRequest $request): JsonResponse
    {
        $allotment = $this->service->upsertAllotment($request->validated());

        return LeaveTypeAllotmentResource::make($allotment)->response();
    }

    // PUT/PATCH /hr/leave-type-allotments/{leaveTypeAllotment}
    public function updateAllotment(Request $request, LeaveTypeAllotment $leaveTypeAllotment): JsonResponse
    {
        $data = $request->validate([
            'days_per_year' => ['required', 'numeric', 'min:0', 'max:365'],
        ]);

        $allotment = $this->service->updateAllotment($leaveTypeAllotment, $data);

        return LeaveTypeAllotmentResource::make($allotment)->response();
    }

    // --- Per-person balances ---

    // GET /hr/employees/{employee}/leave-balances
    public function balancesIndex(Employee $employee): JsonResponse
    {
        return LeaveBalanceResource::collection($this->service->balancesFor($employee))->response();
    }

    // PUT /hr/employees/{employee}/leave-balances — add a type or change the days.
    public function upsertBalance(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'leave_type_id' => ['required', 'integer', 'exists:hr_leave_types,id'],
            'entitled_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $balance = $this->service->upsertBalance($employee, $data);

        return LeaveBalanceResource::make($balance)->response();
    }

    // DELETE /hr/leave-balances/{leaveBalance} — remove a type from this person.
    public function destroyBalance(LeaveBalance $leaveBalance): Response
    {
        $this->service->removeBalance($leaveBalance);

        return response()->noContent();
    }
}
