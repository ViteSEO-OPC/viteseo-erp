<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Resources\MeetingResource;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Meeting;
use App\Modules\Hr\Services\MeetingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MeetingController extends Controller
{
    public function __construct(private readonly MeetingService $service) {}

    // POST /hr/employees/{employee}/meetings — HR schedules a meeting with an
    // employee (e.g. about attendance). Notifies the employee (computed bell + list).
    public function store(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'agenda' => ['nullable', 'string', 'max:1000'],
        ]);

        return MeetingResource::make($this->service->scheduleForEmployee($employee, $data, $request->user()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    // GET /hr/me/meetings — the caller's meeting invites (anon-safe routing).
    public function meIndex(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['data' => []]);
        }

        return MeetingResource::collection($this->service->myMeetings($employee))->response();
    }

    // POST /hr/me/meetings/{meeting}/respond — accept | decline (identity-safe).
    public function respond(Request $request, Meeting $meeting): JsonResponse
    {
        $data = $request->validate(['response' => ['required', 'in:accepted,declined']]);
        $employee = $request->user()->employee;

        return MeetingResource::make($this->service->respond($meeting, $employee, $data['response']))->response();
    }
}
