<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\MoveCandidateRequest;
use App\Modules\Hr\Http\Requests\SendCandidateEmailRequest;
use App\Modules\Hr\Http\Requests\StoreCandidateRequest;
use App\Modules\Hr\Http\Requests\StoreRequisitionRequest;
use App\Modules\Hr\Http\Resources\CandidateEmailResource;
use App\Modules\Hr\Http\Resources\CandidateResource;
use App\Modules\Hr\Http\Resources\RequisitionResource;
use App\Modules\Hr\Models\Candidate;
use App\Modules\Hr\Models\Requisition;
use App\Modules\Hr\Services\CandidateEmailService;
use App\Modules\Hr\Services\RecruitmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class RecruitmentController extends Controller
{
    public function __construct(
        private readonly RecruitmentService $service,
        private readonly CandidateEmailService $emails,
    ) {}

    // GET /api/v1/hr/requisitions
    public function requisitionsIndex(Request $request): JsonResponse
    {
        // Applied by the DATABASE. `status` filters the requisition's `stage` column.
        return RequisitionResource::collection(
            $this->service->listRequisitions($request->only(['search', 'status', 'per_page'])),
        )->response();
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

    // GET /api/v1/hr/candidates/{candidate}/email/draft?type=… — what the compose modal
    // opens with: the stage template, or the last email of this type if there was one.
    public function emailDraft(Request $request, Candidate $candidate): JsonResponse
    {
        $type = $request->validate([
            'type' => ['required', Rule::in(RecruitmentService::EMAIL_TYPES)],
        ])['type'];

        return response()->json(['data' => $this->emails->draft($candidate, $type)]);
    }

    // POST /api/v1/hr/candidates/{candidate}/email — send the composed email.
    // Returns the updated candidate (the board reads `emails_sent`) alongside the log row.
    public function sendEmail(SendCandidateEmailRequest $request, Candidate $candidate): JsonResponse
    {
        $email = $this->emails->send(
            $candidate,
            $request->validated(),
            $request->file('attachments') ?? [],
        );

        return response()->json([
            'data' => [
                'candidate' => CandidateResource::make($candidate->fresh('requisition')),
                'email' => CandidateEmailResource::make($email),
            ],
        ]);
    }

    // GET /api/v1/hr/candidates/{candidate}/emails — everything sent to this candidate.
    public function emailHistory(Candidate $candidate): JsonResponse
    {
        return CandidateEmailResource::collection($this->emails->history($candidate))->response();
    }

    // POST /api/v1/hr/candidates/{candidate}/reject — move to rejected with a reason.
    // Sending the rejection email is a separate, composed step.
    public function reject(Request $request, Candidate $candidate): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return CandidateResource::make(
            $this->service->reject($candidate, $data['reason'], $data['note'] ?? null),
        )->response();
    }
}
