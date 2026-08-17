<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StoreWorkSuspensionRequest;
use App\Modules\Hr\Http\Resources\WorkSuspensionResource;
use App\Modules\Hr\Models\WorkSuspension;
use App\Modules\Hr\Services\WorkSuspensionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WorkSuspensionController extends Controller
{
    public function __construct(private readonly WorkSuspensionService $service) {}

    /**
     * GET /api/v1/hr/work-suspensions — every declaration.
     *
     * Readable by any authenticated user, not just HR: the employee's time clock says "work is
     * suspended today" off the back of it, and a rule employees cannot see is a rule they find
     * out about by being marked absent.
     */
    public function index(): JsonResponse
    {
        return WorkSuspensionResource::collection($this->service->list())->response();
    }

    // POST /api/v1/hr/work-suspensions — declare a date non-working (HR only).
    public function store(StoreWorkSuspensionRequest $request): JsonResponse
    {
        $result = $this->service->declare($request->validated(), $request->user());

        return WorkSuspensionResource::make($result['suspension'])
            // The stale-payroll warning rides in meta, because it is true of the PERIOD rather
            // than of the row — and a screen that drops it would silently leave figures wrong.
            ->additional(['meta' => ['warning' => $result['warning']]])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    // PUT /api/v1/hr/work-suspensions/{workSuspension} — amend it, typically paid ⇄ unpaid.
    public function update(StoreWorkSuspensionRequest $request, WorkSuspension $workSuspension): JsonResponse
    {
        $result = $this->service->update($workSuspension, $request->validated(), $request->user());

        return WorkSuspensionResource::make($result['suspension'])
            ->additional(['meta' => ['warning' => $result['warning']]])
            ->response();
    }

    // DELETE /api/v1/hr/work-suspensions/{workSuspension} — back to an ordinary workday.
    public function destroy(Request $request, WorkSuspension $workSuspension): JsonResponse
    {
        $this->service->withdraw($workSuspension, $request->user());

        return response()->json(['data' => ['id' => $workSuspension->id]]);
    }
}
