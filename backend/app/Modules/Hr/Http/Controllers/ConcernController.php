<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StoreConcernRequest;
use App\Modules\Hr\Http\Resources\ConcernResource;
use App\Modules\Hr\Http\Resources\MeetingResource;
use App\Modules\Hr\Models\Concern;
use App\Modules\Hr\Services\ConcernService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ConcernController extends Controller
{
    public function __construct(private readonly ConcernService $service) {}

    // GET /hr/concerns — HR/Admin queue (identity hidden for anonymous).
    public function index(): JsonResponse
    {
        return ConcernResource::collection($this->service->listForHr())->response();
    }

    // GET /hr/me/concerns — the caller's own concerns.
    public function meIndex(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['data' => []]);
        }

        return ConcernResource::collection($this->service->listForEmployee($employee))->response();
    }

    // POST /hr/me/concerns — submit a concern (named or anonymous).
    public function store(StoreConcernRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['message' => 'Only employees can submit concerns.'], Response::HTTP_FORBIDDEN);
        }

        return ConcernResource::make($this->service->create($employee, $request->validated()))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    // POST /hr/concerns/{concern}/status — under_review | resolved (HR only).
    public function status(Request $request, Concern $concern): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:under_review,resolved']]);

        return ConcernResource::make($this->service->setStatus($concern, $data['status'], $request->user()))->response();
    }

    // POST /hr/concerns/{concern}/meetings — schedule a meeting (HR only).
    public function scheduleMeeting(Request $request, Concern $concern): JsonResponse
    {
        $data = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'agenda' => ['nullable', 'string', 'max:1000'],
        ]);

        return MeetingResource::make($this->service->scheduleMeeting($concern, $data, $request->user()))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
