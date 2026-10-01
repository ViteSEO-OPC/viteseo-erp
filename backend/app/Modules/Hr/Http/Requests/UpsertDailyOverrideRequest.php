<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpsertDailyOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->isMethod('get')) {
            return ['date' => ['nullable', 'date_format:Y-m-d']];
        }

        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'grace_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            // The compensable-day threshold, in MINUTES. Floor of 1 because zero would mean
            // "clocking in at all earns a full day", which is not a shortened day, it is no
            // day at all. Ceiling of 24h so a typo cannot declare an unreachable bar that
            // silently makes every employee undertime.
            'min_full_day_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            // HR has seen which completed payroll runs this date sits inside. A past date is
            // allowed on purpose — see DailyOverrideService::upsert() — but not blindly.
            'acknowledge_repricing' => ['sometimes', 'boolean'],
        ];
    }
}
