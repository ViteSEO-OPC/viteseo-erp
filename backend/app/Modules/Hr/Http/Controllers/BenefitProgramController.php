<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StoreBenefitProgramRequest;
use App\Modules\Hr\Http\Requests\UpdateBenefitProgramRequest;
use App\Modules\Hr\Http\Resources\BenefitProgramResource;
use App\Modules\Hr\Http\Resources\MyBenefitResource;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\AllowanceService;
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

    /**
     * GET /api/v1/hr/benefit-programs/{benefitProgram}/enrollments — Slice B2, hr_officer only.
     *
     * Who is on this plan, at what tier and share, since when — plus everyone the plan's rule
     * allows who is NOT on it, because "who could I add" is the other half of the screen.
     */
    public function planEnrollments(BenefitProgram $benefitProgram): JsonResponse
    {
        return response()->json($this->enrolmentPayload($benefitProgram));
    }

    /**
     * PUT /api/v1/hr/benefit-programs/{benefitProgram}/enrollments — Slice B2, hr_officer only.
     *
     * Enrol people on a plan, or change their tier, share or dates. A row the plan's rule does not
     * cover, or one naming a tier the plan does not have, is REPORTED BACK rather than written —
     * the two faults B5 items 1 and 2 closed for the assignment path that has since been deleted.
     */
    public function updatePlanEnrollments(Request $request, BenefitProgram $benefitProgram): JsonResponse
    {
        if ($benefitProgram->kind !== 'plan') {
            return response()->json([
                'message' => 'Only a company plan has enrolments. An allowance covers people by its rule.',
                'errors' => ['kind' => ['This programme is not a plan.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $request->validate([
            'enrollments' => ['required', 'array'],
            'enrollments.*.employee_id' => ['required', 'integer', 'exists:hr_employees,id'],
            'enrollments.*.tier' => ['nullable', 'string', 'max:40'],
            'enrollments.*.employee_share' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'enrollments.*.enrolled_at' => ['nullable', 'date'],
            'enrollments.*.ended_at' => ['nullable', 'date'],
        ]);

        $result = $this->service->syncPlanEnrolments(
            $benefitProgram,
            $data['enrollments'],
            $request->user()?->id,
        );

        // 200 with the rejections listed, not a blanket 422: enrolling eight people should not be
        // undone because one of them turned out to be ineligible.
        return response()->json(
            $this->enrolmentPayload($benefitProgram->fresh()) + ['rejected' => $result['rejected']],
        );
    }

    /**
     * DELETE /api/v1/hr/benefit-programs/{benefitProgram}/enrollments/{employee} — Slice B2.
     *
     * Ends the enrolment on a date rather than deleting the row: the row is what lets a past
     * payslip explain the deduction it charged.
     */
    public function endPlanEnrollment(BenefitProgram $benefitProgram, Employee $employee): JsonResponse
    {
        // 404 rather than a 200 that changed nothing: taking somebody off a plan they were never
        // on is a mistake worth surfacing, not absorbing.
        abort_unless($this->service->endPlanEnrolment($benefitProgram, $employee), 404);

        return response()->json($this->enrolmentPayload($benefitProgram->fresh()));
    }

    /** @return array<string, mixed> */
    private function enrolmentPayload(BenefitProgram $program): array
    {
        $enrolled = $program->employees()->with('user')->get()->map(fn (Employee $e) => [
            'employee_id' => (int) $e->id,
            'name' => $e->user?->name ?? trim($e->first_name . ' ' . $e->last_name),
            'tier' => $e->pivot->tier,
            'employee_share' => $e->pivot->employee_share,
            'enrolled_at' => $e->pivot->enrolled_at,
            'ended_at' => $e->pivot->ended_at,
        ])->values();

        $onPlan = $enrolled->pluck('employee_id')->all();

        $eligible = Employee::with('user')
            ->whereIn('id', $this->service->eligibleEmployeeIds($program))
            ->whereNotIn('id', $onPlan ?: [0])
            ->get()
            ->map(fn (Employee $e) => [
                'employee_id' => (int) $e->id,
                'name' => $e->user?->name ?? trim($e->first_name . ' ' . $e->last_name),
            ])
            ->values();

        $active = $enrolled->filter(fn ($r) => $r['ended_at'] === null);

        return [
            'data' => ['enrolled' => $enrolled, 'eligible' => $eligible],
            'meta' => [
                'tiers' => $program->tiers ?? [],
                'deduction_cadence' => $program->deduction_cadence,
                // B2 item 3 — "deducts every cutoff" plus "nobody has a share" means deduct
                // nothing, which is almost never what HR intended and looks finished on screen.
                'deducts_but_no_shares' => $program->deduction_cadence !== null
                    && $active->every(fn ($r) => ! ((float) ($r['employee_share'] ?? 0) > 0)),
            ],
        ];
    }

    /**
     * GET /api/v1/hr/me/benefit-programs — the caller's own benefits, with what each is worth.
     *
     * Slice B12 — the money comes from `AllowanceService`, the same code payroll runs, so the
     * total on this page and the allowance line on the payslip cannot disagree. A second
     * implementation of "what an allowance is worth" is exactly the drift this audit exists to
     * remove.
     */
    public function mine(Request $request, AllowanceService $allowances): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(['data' => []]);
        }

        // Monthly, per programme, honouring frequency and this employee's own amount (B14).
        $lines = collect($allowances->linesFor($employee))->keyBy('program_id');

        $programs = $this->service->forEmployee($employee)->each(function (BenefitProgram $program) use ($lines) {
            if ($program->kind === 'allowance') {
                $program->setAttribute('monthly_amount', $lines[$program->id]['amount'] ?? 0);

                return;
            }

            // A company plan's numbers are per-enrolment, so they come off the pivot.
            $program->setAttribute('employee_share_amount', $program->pivot?->employee_share);
            $program->setAttribute('employee_tier', $program->pivot?->tier);
        });

        return MyBenefitResource::collection($programs)->response();
    }
}
