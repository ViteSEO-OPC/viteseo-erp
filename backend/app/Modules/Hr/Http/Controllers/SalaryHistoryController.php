<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StoreSalaryHistoryRequest;
use App\Modules\Hr\Http\Requests\UpdateSalaryHistoryRequest;
use App\Modules\Hr\Http\Resources\SalaryHistoryResource;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\SalaryHistory;
use App\Modules\Hr\Services\SalaryHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Hand-corrections to the compensation timeline (Compensation tab). HR-only.
 *
 * Every row here prices payroll, so unlike the employment timeline these writes are gated — see
 * SalaryHistoryService for which are refused outright and which need an acknowledgement.
 */
class SalaryHistoryController extends Controller
{
    public function __construct(private readonly SalaryHistoryService $service) {}

    // POST /api/v1/hr/employees/{employee}/salary-histories
    public function store(StoreSalaryHistoryRequest $request, Employee $employee): JsonResponse
    {
        return SalaryHistoryResource::make(
            $this->service->create($employee, $request->validated()),
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    // PUT/PATCH /api/v1/hr/salary-histories/{salaryHistory}
    public function update(
        UpdateSalaryHistoryRequest $request,
        SalaryHistory $salaryHistory,
    ): JsonResponse {
        return SalaryHistoryResource::make(
            $this->service->update($salaryHistory, $request->validated()),
        )->response();
    }

    // DELETE /api/v1/hr/salary-histories/{salaryHistory}
    public function destroy(SalaryHistory $salaryHistory): Response
    {
        $this->service->delete($salaryHistory);

        return response()->noContent();
    }
}
