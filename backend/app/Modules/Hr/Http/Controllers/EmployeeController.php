<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\RehireEmployeeRequest;
use App\Modules\Hr\Http\Requests\ReopenRequest;
use App\Modules\Hr\Http\Requests\SeparateEmployeeRequest;
use App\Modules\Hr\Http\Requests\SetHrRoleRequest;
use App\Modules\Hr\Http\Requests\RenameEmployeeDocumentRequest;
use App\Modules\Hr\Http\Requests\StoreEmployeeDocumentRequest;
use App\Modules\Hr\Http\Requests\StoreEmployeePhotoRequest;
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
        // `?status=former` lists people who have left, so they can be found and rehired.
        // Anything unrecognised falls back to 'active', which is the directory's existing behaviour.
        $status = $request->query('status');
        $status = in_array($status, ['active', 'former', 'all'], true) ? $status : 'active';

        $employees = $this->service->list($request->query('search'), $status);

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

        // The Compensation screen needs the derived daily rate. Asked for explicitly
        // here and on update(); the directory does not carry the cost.
        return EmployeeResource::make($employee)->withPayrollChecks()->response();
    }

    // PUT/PATCH /api/v1/hr/employees/{employee} — HR edits a record.
    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $employee = $this->service->update($employee, $request->validated());

        // A salary edit changes the daily rate, so the response has to carry the new one — the
        // screen that just saved is the screen showing the check.
        return EmployeeResource::make($employee)->withPayrollChecks()->response();
    }

    // POST /api/v1/hr/employees/{employee}/photo — set/replace the profile picture.
    public function updatePhoto(StoreEmployeePhotoRequest $request, Employee $employee): JsonResponse
    {
        $employee = $this->service->setPhoto($employee, $request->file('photo'));

        return EmployeeResource::make($employee)->response();
    }

    // DELETE /api/v1/hr/employees/{employee}/photo — back to initials.
    public function destroyPhoto(Employee $employee): JsonResponse
    {
        return EmployeeResource::make($this->service->removePhoto($employee))->response();
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

    // PATCH /api/v1/hr/employees/{employee}/documents/{document} — rename it.
    public function renameDocument(
        RenameEmployeeDocumentRequest $request,
        Employee $employee,
        EmployeeDocument $document,
    ): JsonResponse {
        abort_unless($document->employee_id === $employee->id, 404);

        return EmployeeDocumentResource::make(
            $this->service->renameDocument($document, $request->validated('name')),
        )->response();
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

    // POST /api/v1/hr/employees/{employee}/reopen-onboarding — undo the above.
    public function reopenOnboarding(ReopenRequest $request, Employee $employee): JsonResponse
    {
        return EmployeeResource::make(
            $this->service->reopenOnboarding($employee, $request->validated('reason')),
        )->response();
    }

    // POST /api/v1/hr/employees/{employee}/hr-role — grant/revoke HR-officer access.
    /** POST /api/v1/hr/employees/{employee}/separate — end someone's employment. */
    public function separate(SeparateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        return EmployeeResource::make(
            $this->service->separate($employee, $request->validated()),
        )->response();
    }

    /**
     * POST /api/v1/hr/employees/{employee}/reinstate — undo a separation, service CONTINUOUS.
     *
     * The route resolves trashed employees (`->withTrashed()`), because the whole point is that the
     * record has been archived. Hire date, leave credits and loan authorisations all stand.
     */
    public function reinstate(ReopenRequest $request, Employee $employee): JsonResponse
    {
        return EmployeeResource::make(
            $this->service->reinstate($employee, $request->validated('reason')),
        )->response();
    }

    /** POST /api/v1/hr/employees/{employee}/rehire — a NEW employment relationship. */
    public function rehire(RehireEmployeeRequest $request, Employee $employee): JsonResponse
    {
        return EmployeeResource::make(
            $this->service->rehire(
                $employee,
                $request->validated('hire_date'),
                $request->validated('reason'),
            ),
        )->response();
    }

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
