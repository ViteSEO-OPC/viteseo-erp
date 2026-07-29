<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\ReopenRequest;
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

    // POST /api/v1/hr/loans/{loan}/reopen — undo a cancellation; collection resumes.
    public function reopen(ReopenRequest $request, EmployeeLoan $loan): JsonResponse
    {
        return EmployeeLoanResource::make(
            $this->service->reopen($loan, $request->validated('reason')),
        )->response();
    }
}
