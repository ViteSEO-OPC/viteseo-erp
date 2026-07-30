<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\ReopenRequest;
use App\Modules\Hr\Http\Requests\StoreDocumentRequestRequest;
use App\Modules\Hr\Http\Resources\DocumentRequestResource;
use App\Modules\Hr\Models\DocumentRequest;
use App\Modules\Hr\Services\DocumentRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DocumentRequestController extends Controller
{
    public function __construct(private readonly DocumentRequestService $service) {}

    // GET /hr/document-requests — HR queue (all). GET /hr/me/document-requests — own.
    public function index(Request $request): JsonResponse
    {
        return DocumentRequestResource::collection($this->service->list($request->user()))->response();
    }

    // POST /hr/me/document-requests — the caller requests a document.
    public function store(StoreDocumentRequestRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['message' => 'Only employees can request documents.'], Response::HTTP_FORBIDDEN);
        }

        return DocumentRequestResource::make($this->service->create($employee, $request->validated()))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    // POST /hr/document-requests/{documentRequest}/fulfill — HR only.
    public function fulfill(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $data = $request->validate([
            'file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->service->fulfill(
            $documentRequest,
            $request->user(),
            $request->file('file'),
            $data['note'] ?? null,
        );

        return DocumentRequestResource::make($updated)->response();
    }

    // POST /hr/document-requests/{documentRequest}/reject — HR only.
    public function reject(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return DocumentRequestResource::make(
            $this->service->reject($documentRequest, $request->user(), $data['reason'] ?? null),
        )->response();
    }

    // POST /hr/document-requests/{documentRequest}/reopen — HR only: undo the decision
    // and re-lock the employee's access (the attached file is discarded).
    public function reopen(ReopenRequest $request, DocumentRequest $documentRequest): JsonResponse
    {
        return DocumentRequestResource::make(
            $this->service->reopen($documentRequest, $request->validated('reason')),
        )->response();
    }
}
