<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\MoveCandidateRequest;
use App\Modules\Hr\Http\Requests\StoreCandidateRequest;
use App\Modules\Hr\Http\Requests\StoreRequisitionRequest;
use App\Modules\Hr\Http\Resources\CandidateResource;
use App\Modules\Hr\Http\Resources\RequisitionResource;
use App\Modules\Hr\Models\Candidate;
use App\Modules\Hr\Models\Requisition;
use App\Modules\Hr\Services\RecruitmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RecruitmentController extends Controller
{
    public function __construct(private readonly RecruitmentService $service) {}

    // GET /api/v1/hr/requisitions
    public function requisitionsIndex(): JsonResponse
    {
        return RequisitionResource::collection($this->service->listRequisitions())->response();
    }

    // POST /api/v1/hr/requisitions
    public function storeRequisition(StoreRequisitionRequest $request): JsonResponse
    {
        $req = $this->service->createRequisition($request->validated());

        return RequisitionResource::make($req)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    // PUT /api/v1/hr/requisitions/{requisition}
    public function updateRequisition(StoreRequisitionRequest $request, Requisition $requisition): JsonResponse
    {
        return RequisitionResource::make(
            $this->service->updateRequisition($requisition, $request->validated()),
        )->response();
    }

    // DELETE /api/v1/hr/requisitions/{requisition}
    public function destroyRequisition(Requisition $requisition): Response
    {
        $this->service->deleteRequisition($requisition);

        return response()->noContent();
    }

    // GET /api/v1/hr/candidates?requisition={id}
    public function candidatesIndex(Request $request): JsonResponse
    {
        $requisitionId = $request->query('requisition');
        $candidates = $requisitionId
            ? $this->service->candidatesFor(Requisition::findOrFail($requisitionId))
            : $this->service->allCandidates();

        return CandidateResource::collection($candidates)->response();
    }

    // POST /api/v1/hr/candidates
    public function storeCandidate(StoreCandidateRequest $request): JsonResponse
    {
        $candidate = $this->service->createCandidate(
            $request->validated(),
            $request->file('resume'),
        );

        return CandidateResource::make($candidate)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    // POST /api/v1/hr/candidates/{candidate}/move
    public function moveCandidate(MoveCandidateRequest $request, Candidate $candidate): JsonResponse
    {
        $updated = $this->service->moveStage($candidate, $request->validated('stage'));

        return CandidateResource::make($updated)->response();
    }

    // POST /api/v1/hr/candidates/{candidate}/hire — creates a draft employee.
    public function hireCandidate(Candidate $candidate): JsonResponse
    {
        return CandidateResource::make($this->service->hire($candidate))->response();
    }

    // POST /api/v1/hr/candidates/{candidate}/email — send a stage-specific email
    // (online/final interview invite, offer, or rejection).
    public function sendEmail(Request $request, Candidate $candidate): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'string']]);

        return CandidateResource::make($this->service->sendEmail($candidate, $data['type']))->response();
    }

    // POST /api/v1/hr/candidates/{candidate}/reject — move to rejected with a reason,
    // optionally emailing the candidate.
    public function reject(Request $request, Candidate $candidate): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'send_email' => ['boolean'],
        ]);

        return CandidateResource::make(
            $this->service->reject($candidate, $data['reason'], $data['note'] ?? null, (bool) ($data['send_email'] ?? false)),
        )->response();
    }
}
