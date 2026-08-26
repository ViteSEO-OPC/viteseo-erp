<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StoreRemoteCheckinRequest;
use App\Modules\Hr\Http\Resources\RemoteCheckinResource;
use App\Modules\Hr\Models\RemoteCheckin;
use App\Modules\Hr\Services\RemoteCheckinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// Off-site time-ins: the employee submits, HR decides.
class RemoteCheckinController extends Controller
{
    public function __construct(private readonly RemoteCheckinService $service) {}

    // POST /api/v1/hr/attendance/remote-checkin
    public function store(StoreRemoteCheckinRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(
                ['message' => 'Only employees can use the time clock.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $checkin = $this->service->submit(
            $employee,
            $request->file('photo'),
            $request->filled('lat') ? (float) $request->validated('lat') : null,
            $request->filled('lng') ? (float) $request->validated('lng') : null,
        );

        return RemoteCheckinResource::make($checkin)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    // GET /api/v1/hr/me/remote-checkin — the caller's newest submission, or null.
    public function me(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        $checkin = $employee ? $this->service->latestFor($employee) : null;

        // `null` rather than 404: "you have never submitted one" is a normal state the
        // panel renders, not an error it should handle.
        return response()->json([
            'data' => $checkin ? RemoteCheckinResource::make($checkin) : null,
        ]);
    }

    // GET /api/v1/hr/attendance/remote-checkins?status=pending — HR's queue.
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', RemoteCheckin::STATUS_PENDING);

        return RemoteCheckinResource::collection(
            // ?status=all lifts the filter and returns the whole history.
            $this->service->list(
                $status === 'all' ? null : $status,
                $request->only(['search', 'per_page']),
            ),
        )->response();
    }

    // POST /api/v1/hr/attendance/remote-checkins/{remoteCheckin}/approve
    public function approve(Request $request, RemoteCheckin $remoteCheckin): JsonResponse
    {
        return RemoteCheckinResource::make(
            $this->service->approve($remoteCheckin, $request->user()),
        )->response();
    }

    // POST /api/v1/hr/attendance/remote-checkins/{remoteCheckin}/reject
    public function reject(Request $request, RemoteCheckin $remoteCheckin): JsonResponse
    {
        // A reason is mandatory: the employee has to know what to fix before re-submitting,
        // and "rejected, no reason given" is how a time clock loses people's trust.
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
        ]);

        return RemoteCheckinResource::make(
            $this->service->reject($remoteCheckin, $request->user(), $validated['note']),
        )->response();
    }
}
