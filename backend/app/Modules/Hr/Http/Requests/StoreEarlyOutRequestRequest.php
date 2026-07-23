<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Models\EarlyOutRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreEarlyOutRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth:sanctum on the route; ownership derived from token
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'work_date' => ['required', 'date'],
            // 24h HH:MM from the client; the shift-window check lives in the service.
            'early_out_time' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /** One live early-out per date: block a duplicate while pending or approved. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $employeeId = $this->user()?->employee?->id;
            if (! $employeeId) {
                return;
            }

            $duplicate = EarlyOutRequest::where('employee_id', $employeeId)
                ->where('work_date', $this->input('work_date'))
                ->whereIn('status', ['pending', 'approved'])
                ->exists();

            if ($duplicate) {
                $validator->errors()->add(
                    'work_date',
                    'You already have a pending or approved early-out for this date.',
                );
            }
        });
    }
}
