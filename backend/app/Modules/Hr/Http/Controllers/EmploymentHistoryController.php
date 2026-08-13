<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StoreEmploymentHistoryRequest;
use App\Modules\Hr\Http\Requests\UpdateEmploymentHistoryRequest;
use App\Modules\Hr\Http\Resources\EmploymentHistoryResource;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmploymentHistory;
use App\Modules\Hr\Services\EmploymentHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** Hand-corrections to the employment timeline (Job tab). HR-only. */
class EmploymentHistoryController extends Controller
{
    public function __construct(private readonly EmploymentHistoryService $service) {}

    // GET /api/v1/hr/employees/{employee}/employment-histories
    public function index(Employee $employee): JsonResponse
    {
        return EmploymentHistoryResource::collection($employee->employmentHistories)->response();
    }

    // POST /api/v1/hr/employees/{employee}/employment-histories
    public function store(StoreEmploymentHistoryRequest $request, Employee $employee): JsonResponse
    {
        return EmploymentHistoryResource::make(
            $this->service->create($employee, $request->validated()),
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    // PUT/PATCH /api/v1/hr/employment-histories/{employmentHistory}
    public function update(
        UpdateEmploymentHistoryRequest $request,
        EmploymentHistory $employmentHistory,
    ): JsonResponse {
        return EmploymentHistoryResource::make(
            $this->service->update($employmentHistory, $request->validated()),
        )->response();
    }

    // DELETE /api/v1/hr/employment-histories/{employmentHistory}
    public function destroy(EmploymentHistory $employmentHistory): Response
    {
        $this->service->delete($employmentHistory);

        return response()->noContent();
    }
}
