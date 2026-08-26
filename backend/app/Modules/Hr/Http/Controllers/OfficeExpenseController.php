<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\ReopenRequest;
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
    public function index(Request $request): JsonResponse
    {
        // Applied by the DATABASE. In the browser they would only narrow the page on screen.
        return OfficeExpenseResource::collection(
            $this->service->list($this->filters($request)),
        )->response();
    }

    /**
     * GET /hr/office-expenses/summary — the spend cards above the ledger, over the same window
     * the table is showing. See OfficeExpenseService::summary().
     */
    public function summary(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->summary($this->filters($request))]);
    }

    /**
     * One list, because the table and the cards above it must read the same window — a summary
     * filtered differently from the rows beneath it contradicts them on the same screen.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->only(['search', 'status', 'per_page', 'from', 'to', 'sort']);
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

    // POST /hr/office-expenses/{officeExpense}/reopen — undo the review.
    public function reopen(ReopenRequest $request, OfficeExpense $officeExpense): JsonResponse
    {
        return OfficeExpenseResource::make(
            $this->service->reopen($officeExpense, $request->validated('reason')),
        )->response();
    }

    // DELETE /hr/office-expenses/{officeExpense}
    public function destroy(OfficeExpense $officeExpense): Response
    {
        $this->service->delete($officeExpense);

        return response()->noContent();
    }
}
