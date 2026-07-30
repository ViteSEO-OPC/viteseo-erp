<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\ReopenRequest;
use App\Modules\Hr\Http\Requests\StoreChangeRequestRequest;
use App\Modules\Hr\Http\Resources\ChangeRequestResource;
use App\Modules\Hr\Models\ChangeRequest;
use App\Modules\Hr\Services\ChangeRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ChangeRequestController extends Controller
{
    public function __construct(private readonly ChangeRequestService $service) {}

    // GET /hr/change-requests — HR queue. GET /hr/me/change-requests — own.
    public function index(Request $request): JsonResponse
    {
        return ChangeRequestResource::collection($this->service->list($request->user()))->response();
    }

    // POST /hr/me/change-requests — the caller proposes a profile change.
    public function store(StoreChangeRequestRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['message' => 'Only employees can request changes.'], Response::HTTP_FORBIDDEN);
        }

        return ChangeRequestResource::collection(
            $this->service->createMany($employee, $request->validated('changes')),
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    // POST /hr/change-requests/{changeRequest}/approve — writes the value + audits.
    public function approve(Request $request, ChangeRequest $changeRequest): JsonResponse
    {
        return ChangeRequestResource::make($this->service->approve($changeRequest, $request->user()))->response();
    }

    // POST /hr/change-requests/{changeRequest}/reject — HR only.
    public function reject(Request $request, ChangeRequest $changeRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return ChangeRequestResource::make(
            $this->service->reject($changeRequest, $request->user(), $data['reason'] ?? null),
        )->response();
    }

    // POST /hr/change-requests/{changeRequest}/reopen — HR only: undo the decision.
    // `meta.restored` says whether the employee's old value went back too; it doesn't
    // when HR has since edited that field directly, so the UI can explain the difference.
    public function reopen(ReopenRequest $request, ChangeRequest $changeRequest): JsonResponse
    {
        $result = $this->service->reopen($changeRequest, $request->validated('reason'));

        return ChangeRequestResource::make($result['request'])
            ->additional(['meta' => ['restored' => $result['restored']]])
            ->response();
    }
}
