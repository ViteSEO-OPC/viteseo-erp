<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\ReopenRequest;
use App\Modules\Hr\Http\Requests\RequestLoanRequest;
use App\Modules\Hr\Http\Requests\StoreEmployeeLoanRequest;
use App\Modules\Hr\Http\Resources\EmployeeLoanResource;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeLoan;
use App\Modules\Hr\Services\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Loans and cash advances repaid through payroll (Slice 11b). HR-only. */
class EmployeeLoanController extends Controller
{
    public function __construct(private readonly LoanService $service) {}

    // GET /api/v1/hr/employees/{employee}/loans
    public function index(Employee $employee): JsonResponse
    {
        return EmployeeLoanResource::collection($this->service->forEmployee($employee))->response();
    }

    // POST /api/v1/hr/employees/{employee}/loans
    public function store(StoreEmployeeLoanRequest $request, Employee $employee): JsonResponse
    {
        return EmployeeLoanResource::make(
            $this->service->create($employee, $request->validated(), $request->user()),
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    // PUT/PATCH /api/v1/hr/loans/{loan}
    public function update(StoreEmployeeLoanRequest $request, EmployeeLoan $loan): JsonResponse
    {
        return EmployeeLoanResource::make(
            $this->service->update($loan, $request->validated(), $request->user()),
        )->response();
    }

    // POST /api/v1/hr/loans/{loan}/cancel
    public function cancel(Request $request, EmployeeLoan $loan): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return EmployeeLoanResource::make(
            $this->service->cancel($loan, $request->user(), $data['reason']),
        )->response();
    }

    /**
     * POST /api/v1/hr/loans/{loan}/approve — Slice B15, hr_officer only.
     *
     * Approving does NOT start collection. The employee still has to authorize the deduction:
     * Labor Code Art. 113 wants their consent, not the company's decision.
     */
    public function approve(Request $request, EmployeeLoan $loan): JsonResponse
    {
        $data = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        return EmployeeLoanResource::make(
            $this->service->approve($loan, $request->user(), $data['note'] ?? null),
        )->response();
    }

    // POST /api/v1/hr/loans/{loan}/reject — hr_officer only.
    public function reject(Request $request, EmployeeLoan $loan): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return EmployeeLoanResource::make(
            $this->service->reject($loan, $request->user(), $data['reason']),
        )->response();
    }

    // GET /api/v1/hr/me/loans — the caller's own loans (identity from the token).
    public function mine(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(['data' => []]);
        }

        return EmployeeLoanResource::collection($this->service->forEmployee($employee))->response();
    }

    // POST /api/v1/hr/me/loans — the employee asks to borrow. Decides nothing.
    public function requestLoan(RequestLoanRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;

        abort_if($employee === null, 404, 'No employee record for this account.');

        return EmployeeLoanResource::make(
            $this->service->request($employee, $request->validated()),
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * POST /api/v1/hr/me/loans/{loan}/authorize — the employee consents to the deduction.
     *
     * The gate the whole slice exists to reach: nothing is deducted from anyone's pay until they
     * have agreed to it in writing. Any authenticated user may call it; the service refuses
     * anybody but the borrower.
     */
    // Named `authorizeDeduction`: the base Controller already has an `authorize($ability, …)`,
    // and overriding it with a different signature is a fatal error, not a warning.
    public function authorizeDeduction(Request $request, EmployeeLoan $loan): JsonResponse
    {
        return EmployeeLoanResource::make(
            $this->service->authorize($loan, $request->user()),
        )->response();
    }

    // POST /api/v1/hr/loans/{loan}/reopen — undo a cancellation; collection resumes.
    public function reopen(ReopenRequest $request, EmployeeLoan $loan): JsonResponse
    {
        return EmployeeLoanResource::make(
            $this->service->reopen($loan, $request->validated('reason')),
        )->response();
    }
}
