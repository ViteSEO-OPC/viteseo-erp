<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StoreBenefitProgramRequest;
use App\Modules\Hr\Http\Requests\UpdateBenefitProgramRequest;
use App\Modules\Hr\Http\Resources\BenefitProgramResource;
use App\Modules\Hr\Http\Resources\MyBenefitResource;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Employee;
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

    /**
     * GET /api/v1/hr/employees/{employee}/allowances — Slice B14, hr_officer only.
     *
     * What this employee is actually paid, allowance by allowance: the programme's amount, their
     * own amount where one has been set, and who set it. Built from the CATALOGUE rather than a
     * fixed list of fields — HR adds and deletes allowances, so the screen has to follow.
     *
     * Not `/benefits`: that path is the statutory checklist (B5 item 8's naming collision).
     */
    public function employeeAllowances(Employee $employee): JsonResponse
    {
        $overrides = $this->service->overridesFor($employee);

        $rows = $this->service->allowancesFor($employee)->map(function (BenefitProgram $program) use ($employee, $overrides) {
            $override = $overrides[(int) $program->id] ?? null;
            $pivot = $override !== null
                ? $employee->benefitPrograms()->where('hr_benefit_programs.id', $program->id)->first()?->pivot
                : null;

            return [
                'program_id' => (int) $program->id,
                'name' => $program->name,
                'frequency' => $program->frequency,
                'tax_treatment' => $program->tax_treatment,
                'color' => $program->color,
                'program_amount' => (float) ($program->amount ?? 0),
                // Null ⇒ follows the programme, which is the default and the common case.
                'amount' => $override,
                'set_by' => $pivot?->amount_set_by
                    ? \App\Models\User::find($pivot->amount_set_by)?->name
                    : null,
                'set_at' => $pivot?->amount_set_at,
            ];
        })->values();

        return response()->json(['data' => $rows]);
    }

    /**
     * PUT /api/v1/hr/employees/{employee}/allowances/{benefitProgram} — Slice B14, hr_officer only.
     *
     * `amount: null` hands the employee back to the programme. Any other value is a deliberate
     * figure and is stamped with who set it.
     */
    public function setEmployeeAllowance(
        Request $request,
        Employee $employee,
        BenefitProgram $benefitProgram,
    ): JsonResponse {
        $data = $request->validate([
            'amount' => ['present', 'nullable', 'numeric', 'min:0', 'max:9999999.99'],
        ]);

        $ok = $this->service->setAllowanceAmount(
            $benefitProgram,
            $employee,
            $data['amount'] === null ? null : (float) $data['amount'],
            $request->user()?->id,
        );

        if (! $ok) {
            // Setting an amount is not a way onto an allowance — membership is the rule.
            return response()->json([
                'message' => 'This employee is not covered by that allowance, so an amount here would pay nothing.',
                'errors' => ['amount' => ['The allowance rule does not cover this employee.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->employeeAllowances($employee->fresh());
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
