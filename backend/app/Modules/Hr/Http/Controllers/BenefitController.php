<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StoreContributionVersionRequest;
use App\Modules\Hr\Http\Resources\ContributionSettingResource;
use App\Modules\Hr\Http\Resources\EmployeeBenefitResource;
use App\Modules\Hr\Models\ContributionSetting;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\ContributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// Company → Benefits. All routes are hr_officer-only (see Routes/api.php).
class BenefitController extends Controller
{
    public function __construct(private readonly ContributionService $service) {}

    // GET /hr/contribution-settings
    public function settingsIndex(): JsonResponse
    {
        return ContributionSettingResource::collection($this->service->settings())->response();
    }

    // PUT/PATCH /hr/contribution-settings/{contributionSetting}
    public function updateSetting(Request $request, ContributionSetting $contributionSetting): JsonResponse
    {
        $data = $request->validate([
            'config' => ['sometimes', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $setting = $this->service->updateSetting($contributionSetting, $data);

        return ContributionSettingResource::make($setting)->response();
    }

    // GET /hr/contribution-versions?type=sss — Slice B3a, the rate history.
    public function versionsIndex(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->versions($request->query('type'))->map(fn ($v) => [
                'id' => $v->id,
                'type' => $v->type,
                'effective_from' => $v->effective_from?->format('Y-m-d'),
                'basis' => $v->basis,
                'employee_rate' => (float) $v->employee_rate,
                'employer_rate' => (float) $v->employer_rate,
                'salary_floor' => $v->salary_floor !== null ? (float) $v->salary_floor : null,
                'salary_cap' => $v->salary_cap !== null ? (float) $v->salary_cap : null,
                'employee_amount_cap' => $v->employee_amount_cap !== null ? (float) $v->employee_amount_cap : null,
                'employer_amount_cap' => $v->employer_amount_cap !== null ? (float) $v->employer_amount_cap : null,
                'fixed_employer_add_on' => (float) $v->fixed_employer_add_on,
                'add_on_min_salary' => $v->add_on_min_salary !== null ? (float) $v->add_on_min_salary : null,
                'note' => $v->note,
                'created_by' => $v->creator?->name,
                'brackets' => $v->brackets->map(fn ($b) => [
                    'min_salary' => (float) $b->min_salary,
                    'msc' => (float) $b->msc,
                ])->values(),
            ]),
        ]);
    }

    // POST /hr/contribution-versions — record a dated rate change.
    public function storeVersion(StoreContributionVersionRequest $request): JsonResponse
    {
        $version = $this->service->saveVersion($request->validated(), $request->user());

        return response()->json(['data' => ['id' => $version->id]], Response::HTTP_CREATED);
    }

    // GET /hr/employees/{employee}/benefits
    public function employeeBenefitsIndex(Employee $employee): JsonResponse
    {
        return EmployeeBenefitResource::collection($this->service->benefitsFor($employee))->response();
    }

    // PUT/PATCH /hr/employees/{employee}/benefits
    public function updateEmployeeBenefits(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'benefits' => ['required', 'array'],
            'benefits.*.contribution_type' => ['required', 'string'],
            'benefits.*.enrolled' => ['required', 'boolean'],
        ]);

        $benefits = $this->service->updateBenefits($employee, $data['benefits']);

        return EmployeeBenefitResource::collection($benefits)->response();
    }
}
