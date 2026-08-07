<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\PayScheduleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePayrollSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route gated by role:hr_officer
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // FIN-025 — the date these settings take effect. Optional: omitted means today,
            // which is the behaviour that existed before versioning. Given, it lets HR record a
            // change that was actually agreed earlier without repricing everything since.
            'effective_from' => ['sometimes', 'nullable', 'date'],
            // FIN-030 — the regional minimum daily wage from the applicable DOLE wage order.
            'minimum_daily_wage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999'],
            'frequency' => ['required', Rule::in(array_keys(PayScheduleService::PERIODS_PER_YEAR))],
            'deduction_timing' => ['nullable', Rule::in(['every', 'first', 'last'])],
            'tax_timing' => ['nullable', Rule::in(['every', 'first', 'last'])],
            'anchor_date' => ['nullable', 'date'],
            'pay_delay_days' => ['nullable', 'integer', 'min:0', 'max:31'],
            // Null = no ceiling, i.e. allowances stay entirely non-taxable.
            // Slice B16 — ₱90,000 governs 13th month AND OTHER BENEFITS, not de minimis. Each
            // de-minimis item has its own ceiling, held on the benefit itself.
            'other_benefits_annual_cap' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }
}
