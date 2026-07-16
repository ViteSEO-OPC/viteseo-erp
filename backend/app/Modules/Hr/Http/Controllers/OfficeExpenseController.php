<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StoreOfficeExpenseRequest;
use App\Modules\Hr\Http\Resources\OfficeExpenseResource;
use App\Modules\Hr\Models\OfficeExpense;
use App\Modules\Hr\Services\OfficeExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// Finance → Office Expenses. hr_officer-only.
class OfficeExpenseController extends Controller
{
    public function __construct(private readonly OfficeExpenseService $service) {}

    // GET /hr/office-expenses
    public function index(): JsonResponse
    {
        return OfficeExpenseResource::collection($this->service->list())->response();
    }

    // POST /hr/office-expenses
    public function store(StoreOfficeExpenseRequest $request): JsonResponse
    {
        $expense = $this->service->create($request->validated(), $request->file('receipt'));

        return OfficeExpenseResource::make($expense)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    // PUT/PATCH /hr/office-expenses/{officeExpense}
    public function update(StoreOfficeExpenseRequest $request, OfficeExpense $officeExpense): JsonResponse
    {
        return OfficeExpenseResource::make(
            $this->service->update($officeExpense, $request->validated(), $request->file('receipt')),
        )->response();
    }

    // POST /hr/office-expenses/{officeExpense}/approve
    public function approve(Request $request, OfficeExpense $officeExpense): JsonResponse
    {
        return OfficeExpenseResource::make($this->service->approve($officeExpense, $request->user()))->response();
    }

    // POST /hr/office-expenses/{officeExpense}/reject
    public function reject(Request $request, OfficeExpense $officeExpense): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return OfficeExpenseResource::make(
            $this->service->reject($officeExpense, $request->user(), $data['reason'] ?? null),
        )->response();
    }

    // DELETE /hr/office-expenses/{officeExpense}
    public function destroy(OfficeExpense $officeExpense): Response
    {
        $this->service->delete($officeExpense);

        return response()->noContent();
    }
}
