<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Resources\ContributionSettingResource;
use App\Modules\Hr\Http\Resources\EmployeeBenefitResource;
use App\Modules\Hr\Models\ContributionSetting;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\ContributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
