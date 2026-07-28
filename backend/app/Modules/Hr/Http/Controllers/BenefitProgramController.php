<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\AssignBenefitsRequest;
use App\Modules\Hr\Http\Requests\StoreBenefitProgramRequest;
use App\Modules\Hr\Http\Requests\UpdateBenefitProgramRequest;
use App\Modules\Hr\Http\Resources\BenefitProgramResource;
use App\Modules\Hr\Http\Resources\MyBenefitResource;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\AllowanceService;
use App\Modules\Hr\Services\AuditService;
use App\Modules\Hr\Services\BenefitProgramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Company → Benefits: the flexible benefit-programs catalogue. Management actions
 * are hr_officer-only; `mine` is the caller's own enrolled programs (any auth user).
 */
class BenefitProgramController extends Controller
{
    // GET /api/v1/hr/benefit-programs/allowance-reconciliation — Slice B1.
    // Employees whose pinned allowance differs from their programme: the working list for
    // switching Benefits to the source of truth.
    public function allowanceReconciliation(AllowanceService $allowances): JsonResponse
    {
        return response()->json(['data' => $allowances->reconciliation()]);
    }

    // DELETE /api/v1/hr/employees/{employee}/allowance-overrides/{program}
    // Hand this employee back to the programme amount.
    public function clearAllowanceOverride(
        Employee $employee,
        BenefitProgram $program,
        AllowanceService $allowances,
        AuditService $audit,
    ): JsonResponse {
        $allowances->clearOverride($employee, $program->id);

        $employee->loadMissing('user');
        $audit->log(
            'updated', 'Benefits',
            "Cleared the {$program->name} override for "
                . ($employee->user?->name ?? $employee->name) . ' — now paid the programme amount',
            $program,
        );

        return response()->json(['data' => $allowances->linesFor($employee->fresh())]);
    }

    public function __construct(private readonly BenefitProgramService $service) {}

    // GET /api/v1/hr/benefit-programs — hr_officer only.
    public function index(): JsonResponse
    {
        return BenefitProgramResource::collection($this->service->list())->response();
    }

    // POST /api/v1/hr/benefit-programs — hr_officer only.
    public function store(StoreBenefitProgramRequest $request): JsonResponse
    {
        $program = $this->service->create($request->validated());

        return BenefitProgramResource::make($program)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    // PUT/PATCH /api/v1/hr/benefit-programs/{benefitProgram} — hr_officer only.
    public function update(UpdateBenefitProgramRequest $request, BenefitProgram $benefitProgram): JsonResponse
    {
        $program = $this->service->update($benefitProgram, $request->validated());

        return BenefitProgramResource::make($program)->response();
    }

    // DELETE /api/v1/hr/benefit-programs/{benefitProgram} — hr_officer only (soft delete).
    public function destroy(BenefitProgram $benefitProgram): Response
    {
        $this->service->delete($benefitProgram);

        return response()->noContent();
    }

    // POST /api/v1/hr/benefit-programs/assign — assign allowances/HMO to employees.
    public function assign(AssignBenefitsRequest $request): JsonResponse
    {
        $this->service->assign($request->validated());

        return BenefitProgramResource::collection($this->service->list())->response();
    }

    // GET /api/v1/hr/me/benefit-programs — the caller's own enrolled programs.
    public function mine(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(['data' => []]);
        }

        return MyBenefitResource::collection($this->service->forEmployee($employee))->response();
    }
}
