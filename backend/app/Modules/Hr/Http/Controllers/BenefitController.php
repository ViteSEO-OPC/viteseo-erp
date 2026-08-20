<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StoreContributionVersionRequest;
use App\Modules\Hr\Http\Resources\ContributionSettingResource;
use App\Modules\Hr\Http\Resources\EmployeeBenefitResource;
use App\Modules\Hr\Models\ContributionSetting;
use App\Modules\Hr\Models\ContributionVersion;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\ContributionScheduleImporter;
use App\Modules\Hr\Services\ContributionService;
use App\Modules\Hr\Services\SpreadsheetReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

// Company → Benefits. All routes are hr_officer-only (see Routes/api.php).
class BenefitController extends Controller
{
    public function __construct(private readonly ContributionService $service) {}

    // GET /hr/contribution-settings
    public function settingsIndex(): JsonResponse
    {
        return ContributionSettingResource::collection($this->service->settings())->response();
    }

    /**
     * PUT/PATCH /hr/contribution-settings/{contributionSetting} — whether the company operates
     * this contribution at all. NOT the rates: those are dated versions.
     */
    public function updateSetting(Request $request, ContributionSetting $contributionSetting): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $setting = $this->service->updateSetting($contributionSetting, $data);

        return ContributionSettingResource::make($setting)->response();
    }

    // GET /hr/contribution-versions?type=sss, the rate history.
    public function versionsIndex(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->versions($request->query('type'))->map(fn ($v) => [
                'id' => $v->id,
                'type' => $v->type,
                'effective_from' => $v->effective_from?->format('Y-m-d'),
                'basis' => $v->basis,
                // WITHOUT THIS the edit dialog seeds its selector from the version in
                // force, finds nothing, defaults to `basic`, and the next rate change silently
                // moves the contribution back off the earnings basis.
                'salary_basis' => $v->salary_basis ?: 'basic',
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
                    'employer_add_on' => $b->employer_add_on !== null ? (float) $b->employer_add_on : null,
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

    /** GET /api/v1/hr/me/benefits — which contributions the CALLER pays into, read-only. */
    public function myBenefits(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(
                ['message' => 'No employee record for this account.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return $this->employeeBenefitsIndex($employee);
    }

    /** POST /hr/contribution-versions/import-schedule. */
    public function importSchedule(
        Request $request,
        SpreadsheetReader $reader,
        ContributionScheduleImporter $importer,
    ): JsonResponse {
        $data = $request->validate([
            // 2 MB is generous for a contribution schedule and keeps a stray upload cheap.
            'file' => ['required', 'file', 'max:2048', 'mimes:xlsx,csv,txt'],
            'columns' => ['sometimes', 'array'],
            'columns.*' => ['nullable', 'string', 'max:3', 'regex:/^[A-Za-z]{1,3}$/'],
        ]);

        $file = $data['file'];
        // Empty values are KEPT: "" means HR said this column is not in the file, which must
        // override the detection rather than fall back to it.
        $columns = array_map(
            fn ($c) => strtoupper((string) $c),
            $request->input('columns', []),
        );

        try {
            $rows = $reader->read($file->getRealPath(), $file->getClientOriginalName());
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $importer->parse($rows, $columns)]);
    }

    // DELETE /hr/contribution-versions/{contributionVersion}.
    // So a version saved with the wrong date can be removed rather than worked around.
    public function destroyVersion(ContributionVersion $contributionVersion): JsonResponse
    {
        // Never leave a type with no version: payroll would resolve nothing and charge ₱0 on every
        // payslip, which looks like a working run.
        $remaining = ContributionVersion::where('type', $contributionVersion->type)
            ->whereKeyNot($contributionVersion->id)
            ->count();

        if ($remaining === 0) {
            throw ValidationException::withMessages([
                'version' => ['This is the only ' . strtoupper($contributionVersion->type)
                    . ' schedule. Removing it would deduct nothing on every payslip — add a replacement first.'],
            ]);
        }

        $this->service->deleteVersion($contributionVersion);

        return response()->json(['data' => ['deleted' => true]]);
    }

    // PUT/PATCH /hr/employees/{employee}/benefits
    public function updateEmployeeBenefits(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'benefits' => ['required', 'array'],
            // The type is checked against the three that exist. It was `['required',
            // 'string']`, so a typo saved a checklist row matching no contribution: the write
            // answered 200, the screen showed the change, and nothing was ever deducted or not
            // deducted because of it. Same shape as the mistyped tier B5 item 2 closed.
            'benefits.*.contribution_type' => ['required', 'in:sss,philhealth,pagibig'],
            'benefits.*.enrolled' => ['required', 'boolean'],
        ]);

        $benefits = $this->service->updateBenefits($employee, $data['benefits']);

        return EmployeeBenefitResource::collection($benefits)->response();
    }
}
