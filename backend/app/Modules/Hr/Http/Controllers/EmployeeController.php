<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\SetHrRoleRequest;
use App\Modules\Hr\Http\Requests\StoreEmployeeDocumentRequest;
use App\Modules\Hr\Http\Requests\StoreEmployeeRequest;
use App\Modules\Hr\Http\Requests\UpdateEmployeeRequest;
use App\Modules\Hr\Http\Resources\EmployeeDocumentResource;
use App\Modules\Hr\Http\Resources\EmployeeResource;
use App\Modules\Hr\Http\Resources\SalaryHistoryResource;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeDocument;
use App\Modules\Hr\Services\DocumentRequestService;
use App\Modules\Hr\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $service) {}

    // GET /api/v1/hr/employees?search= — HR directory.
    public function index(Request $request): JsonResponse
    {
        $employees = $this->service->list($request->query('search'));

        return EmployeeResource::collection($employees)->response();
    }

    // POST /api/v1/hr/employees — creates user + employee + leave balances.
    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = $this->service->create($request->validated());

        return EmployeeResource::make($employee)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    // GET /api/v1/hr/employees/{employee} — HR views one record.
    public function show(Employee $employee): JsonResponse
    {
        $employee->load(['user.roles', 'department', 'manager.user', 'employmentHistories']);

        return EmployeeResource::make($employee)->response();
    }

    // PUT/PATCH /api/v1/hr/employees/{employee} — HR edits a record.
    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $employee = $this->service->update($employee, $request->validated());

        return EmployeeResource::make($employee)->response();
    }

    // GET /api/v1/hr/employees/{employee}/documents — Documents tab.
    public function documentsIndex(Employee $employee): JsonResponse
    {
        return EmployeeDocumentResource::collection($this->service->documents($employee))->response();
    }

    // GET /api/v1/hr/me/documents — the caller's own HR-uploaded documents, listed but
    // download-gated: the URL is only present once a request for the doc is fulfilled.
    public function myDocuments(Request $request, DocumentRequestService $documentRequests): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => $documentRequests->myDocumentsWithAccess($employee)]);
    }

    // POST /api/v1/hr/employees/{employee}/documents — upload a file.
    public function storeDocument(StoreEmployeeDocumentRequest $request, Employee $employee): JsonResponse
    {
        $document = $this->service->addDocument(
            $employee,
            $request->file('file'),
            $request->validated('name'),
            $request->validated('label') ?? 'Uploaded',
        );

        return EmployeeDocumentResource::make($document)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    // DELETE /api/v1/hr/employees/{employee}/documents/{document}
    public function destroyDocument(Employee $employee, EmployeeDocument $document): Response
    {
        abort_unless($document->employee_id === $employee->id, 404);
        $this->service->deleteDocument($document);

        return response()->noContent();
    }

    // GET /api/v1/hr/employees/{employee}/salary-history — Compensation tab.
    public function salaryHistory(Employee $employee): JsonResponse
    {
        return SalaryHistoryResource::collection($this->service->salaryHistory($employee))->response();
    }

    // POST /api/v1/hr/employees/{employee}/activate — mark onboarding complete.
    public function activate(Employee $employee): JsonResponse
    {
        return EmployeeResource::make($this->service->activate($employee))->response();
    }

    // POST /api/v1/hr/employees/{employee}/hr-role — grant/revoke HR-officer access.
    public function setHrRole(SetHrRoleRequest $request, Employee $employee): JsonResponse
    {
        $employee = $this->service->setHrRole($employee, $request->boolean('is_hr_officer'));

        return EmployeeResource::make($employee)->response();
    }

    // GET /api/v1/hr/me/employee — the caller's own record (identity from token).
    public function me(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(
                ['message' => 'No employee record for this account.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $employee->load(['user.roles', 'department', 'manager.user']);

        return EmployeeResource::make($employee)->response();
    }
}
