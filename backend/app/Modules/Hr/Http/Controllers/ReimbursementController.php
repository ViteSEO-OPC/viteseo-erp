<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\ReopenRequest;
use App\Modules\Hr\Http\Requests\StoreReimbursementRequest;
use App\Modules\Hr\Http\Resources\ReimbursementResource;
use App\Modules\Hr\Models\Reimbursement;
use App\Modules\Hr\Services\ReimbursementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReimbursementController extends Controller
{
    public function __construct(private readonly ReimbursementService $service) {}

    // GET /api/v1/hr/reimbursements — HR sees all; employees see their own.
    public function index(Request $request): JsonResponse
    {
        $claims = $this->service->list($request->user());

        return ReimbursementResource::collection($claims)->response();
    }

    // POST /api/v1/hr/reimbursements — the caller files their own claim.
    public function store(StoreReimbursementRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(
                ['message' => 'Only employees can file claims.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        // Accept `receipts[]` (multi-file) and a lone legacy `receipt`, in order.
        $receipts = array_merge(
            $request->file('receipts', []),
            array_filter([$request->file('receipt')]),
        );

        $claim = $this->service->create(
            $employee,
            $request->validated(),
            $receipts,
        );

        return ReimbursementResource::make($claim)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function approve(Request $request, Reimbursement $reimbursement): JsonResponse
    {
        return ReimbursementResource::make(
            $this->service->approve($reimbursement, $request->user()),
        )->response();
    }

    public function reject(Request $request, Reimbursement $reimbursement): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return ReimbursementResource::make(
            $this->service->reject($reimbursement, $request->user(), $data['reason']),
        )->response();
    }

    public function markPaid(Request $request, Reimbursement $reimbursement): JsonResponse
    {
        return ReimbursementResource::make(
            $this->service->markPaid($reimbursement, $request->user()),
        )->response();
    }

    // POST .../reopen — undo the last mark (paid → approved, decided → pending).
    public function reopen(ReopenRequest $request, Reimbursement $reimbursement): JsonResponse
    {
        return ReimbursementResource::make(
            $this->service->reopen($reimbursement, $request->validated('reason')),
        )->response();
    }
}
