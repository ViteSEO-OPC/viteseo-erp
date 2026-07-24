<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StoreDepartmentRequest;
use App\Modules\Hr\Http\Requests\UpdateDepartmentRequest;
use App\Modules\Hr\Http\Resources\DepartmentResource;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Services\DepartmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class DepartmentController extends Controller
{
    public function __construct(private readonly DepartmentService $service) {}

    // GET /api/v1/hr/departments — any authenticated user (needed for dropdowns).
    public function index(): JsonResponse
    {
        return DepartmentResource::collection($this->service->list())->response();
    }

    // GET /api/v1/hr/departments/{department}
    public function show(Department $department): JsonResponse
    {
        return DepartmentResource::make($department->load(['head.user', 'positions', 'employees.user']))->response();
    }

    // POST /api/v1/hr/departments — hr_officer only.
    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = $this->service->create($request->validated());

        return DepartmentResource::make($department)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    // PUT/PATCH /api/v1/hr/departments/{department} — hr_officer only.
    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $department = $this->service->update($department, $request->validated());

        return DepartmentResource::make($department)->response();
    }

    // DELETE /api/v1/hr/departments/{department} — hr_officer only (soft delete).
    public function destroy(Department $department): Response
    {
        $this->service->delete($department);

        return response()->noContent();
    }

    // POST /api/v1/hr/departments/{department}/archive — hr_officer only.
    public function archive(Department $department): JsonResponse
    {
        return DepartmentResource::make($this->service->archive($department))->response();
    }

    // POST /api/v1/hr/departments/{department}/restore — hr_officer only.
    public function restore(Department $department): JsonResponse
    {
        return DepartmentResource::make($this->service->restore($department))->response();
    }
}
