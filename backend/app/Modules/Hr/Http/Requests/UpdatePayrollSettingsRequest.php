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
            // The date these settings take effect. Optional: omitted means today,
            // which is the behaviour that existed before versioning. Given, it lets HR record a
            // change that was actually agreed earlier without repricing everything since.
            'effective_from' => ['sometimes', 'nullable', 'date'],
            // The regional minimum daily wage from the applicable DOLE wage order.
            'minimum_daily_wage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999'],
            'frequency' => ['required', Rule::in(array_keys(PayScheduleService::PERIODS_PER_YEAR))],
            // NO `first` FOR CONTRIBUTIONS. A statutory contribution on `earnings` is a
            // monthly amount on a monthly base, and the first cutoff cannot know the month: it can
            // only ever be provisional, which means the last cutoff has to carry an adjustment —
            // and once it does, "first cutoff only" IS a split, just a worse-shaped one (the whole
            // month taken on the 15th and handed back on the 30th). Withholding tax and allowances
            // keep all three: tax self-corrects at the year-end true-up, and an allowance is a
            // fixed monthly figure with no base to discover.
            'deduction_timing' => ['nullable', Rule::in(['every', 'last'])],
            'tax_timing' => ['nullable', Rule::in(['every', 'first', 'last'])],
            // Same three values as the timings above, and the same meaning.
            'allowance_timing' => ['nullable', Rule::in(['every', 'first', 'last'])],
            'anchor_date' => ['nullable', 'date'],
            'pay_delay_days' => ['nullable', 'integer', 'min:0', 'max:31'],
            // Null = no ceiling, i.e. allowances stay entirely non-taxable.
            // ₱90,000 governs 13th month AND OTHER BENEFITS, not de minimis. Each
            // de-minimis item has its own ceiling, held on the benefit itself.
            'other_benefits_annual_cap' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }
}
