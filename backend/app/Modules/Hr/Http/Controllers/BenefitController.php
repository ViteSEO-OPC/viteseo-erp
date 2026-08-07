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
     * this contribution at all. NOT the rates: those are dated versions (B3a).
     *
     * Slice B9 item 5 — `config` is no longer accepted. It took `['sometimes','array']`, so
     * anything at all saved cleanly into a field payroll has not priced from since B3a: a typo
     * (`raet`) was a 200, and the only thing still reading it was the Compensation report's
     * legend, which item 2 has now pointed at the version in force. An unvalidated write to a
     * dead field, behind a button labelled "update rates", is the exact trap this audit keeps
     * finding. The column stays for the historical run snapshots that captured it.
     */
    public function updateSetting(Request $request, ContributionSetting $contributionSetting): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
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
                // Slice B19 — WITHOUT THIS the edit dialog seeds its selector from the version in
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

    /**
     * POST /hr/contribution-versions/import-schedule — Slice B8.
     *
     * Reads the agency's own published spreadsheet and returns the bands it found, the columns it
     * used, and anything it is unsure about. It saves NOTHING: HR confirms the reading, then the
     * ordinary create-a-version endpoint writes it. A schedule imported with a column misread is
     * worse than no schedule, so the confirmation step is not optional.
     *
     * `columns` lets HR re-point a column the detection got wrong (`{"msc": "D"}`), which is what
     * makes a differently-laid-out circular importable without a code change.
     */
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

    // DELETE /hr/contribution-versions/{contributionVersion} — Slice B8.
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

    // GET /hr/employees/{employee}/statutory-preview — Slice B7.
    // What the government rates would charge this employee, beside any per-employee override.
    // Its own endpoint rather than a field on EmployeeResource: this resolves rate versions per
    // employee, and hanging it off the list resource would be three extra lookups per row.
    public function statutoryPreview(Employee $employee): JsonResponse
    {
        return response()->json(['data' => $this->service->overridePreviewFor($employee)]);
    }

    // PUT/PATCH /hr/employees/{employee}/benefits
    public function updateEmployeeBenefits(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'benefits' => ['required', 'array'],
            // Slice B9 — the type is checked against the three that exist. It was `['required',
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
