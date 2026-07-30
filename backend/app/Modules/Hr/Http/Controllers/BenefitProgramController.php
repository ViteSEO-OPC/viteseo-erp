<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StoreBenefitProgramRequest;
use App\Modules\Hr\Http\Requests\UpdateBenefitProgramRequest;
use App\Modules\Hr\Http\Resources\BenefitProgramResource;
use App\Modules\Hr\Http\Resources\MyBenefitResource;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Services\BenefitProgramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Company → Benefits: the flexible benefit-programs catalogue. Management actions
 * are hr_officer-only; `mine` is the caller's own enrolled programs (any auth user).
 */
class BenefitProgramController extends Controller
{
    public function __construct(private readonly BenefitProgramService $service) {}

    // GET /api/v1/hr/benefit-programs — hr_officer only.
    public function index(): JsonResponse
    {
        return BenefitProgramResource::collection($this->service->list())->response();
    }

    // POST /api/v1/hr/benefit-programs — hr_officer only.
    public function store(StoreBenefitProgramRequest $request): JsonResponse
    {
        $program = $this->service->create($request->validated());

        return BenefitProgramResource::make($program)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    // PUT/PATCH /api/v1/hr/benefit-programs/{benefitProgram} — hr_officer only.
    public function update(UpdateBenefitProgramRequest $request, BenefitProgram $benefitProgram): JsonResponse
    {
        $program = $this->service->update($benefitProgram, $request->validated());

        return BenefitProgramResource::make($program)->response();
    }

    // DELETE /api/v1/hr/benefit-programs/{benefitProgram} — hr_officer only (soft delete).
    public function destroy(BenefitProgram $benefitProgram): Response
    {
        $this->service->delete($benefitProgram);

        return response()->noContent();
    }

    // GET /api/v1/hr/me/benefit-programs — the caller's own enrolled programs.
    public function mine(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(['data' => []]);
        }

        return MyBenefitResource::collection($this->service->forEmployee($employee))->response();
    }
}
