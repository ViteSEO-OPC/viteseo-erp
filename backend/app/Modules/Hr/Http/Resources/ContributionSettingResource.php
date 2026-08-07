<?php

namespace App\Modules\Hr\Http\Resources;

use App\Modules\Hr\Services\ContributionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContributionSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Slice B3a/b — `config` is the LEGACY row and is no longer what payroll reads. The card
        // showed SSS at 4.5% while payroll charged 5%, because this resource still served the old
        // column. `current` is the version actually in force today, so the page and the payslip
        // agree.
        $version = app(ContributionService::class)->versionsAsOf(
            now(config('app.timezone_local'))->toDateString(),
        )->get($this->type);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'config' => $this->config, // kept for backwards compatibility; do not read for rates
            'is_active' => $this->is_active,
            'current' => $version ? [
                'effective_from' => $version->effective_from?->format('Y-m-d'),
                'basis' => $version->basis,
                // Slice B19 — which salary figure this is charged on: `basic` (the profile rate)
                // or `earnings` (what the period actually paid).
                'salary_basis' => $version->salary_basis ?: 'basic',
                'employee_rate' => (float) $version->employee_rate,
                'employer_rate' => (float) $version->employer_rate,
                'salary_floor' => $version->salary_floor !== null ? (float) $version->salary_floor : null,
                'salary_cap' => $version->salary_cap !== null ? (float) $version->salary_cap : null,
                'employee_amount_cap' => $version->employee_amount_cap !== null ? (float) $version->employee_amount_cap : null,
                'fixed_employer_add_on' => (float) $version->fixed_employer_add_on,
                'bracket_count' => $version->basis === 'bracket' ? $version->brackets->count() : 0,
            ] : null,
        ];
    }
}
