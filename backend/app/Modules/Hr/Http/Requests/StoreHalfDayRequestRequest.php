<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Models\HalfDayRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreHalfDayRequestRequest extends FormRequest
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
            'period' => ['required', 'in:AM,PM'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /** One live request per date+period: block a duplicate while pending or approved. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $employeeId = $this->user()?->employee?->id;
            if (! $employeeId) {
                return;
            }

            $duplicate = HalfDayRequest::where('employee_id', $employeeId)
                ->where('work_date', $this->input('work_date'))
                ->where('period', $this->input('period'))
                ->whereIn('status', ['pending', 'approved'])
                ->exists();

            if ($duplicate) {
                $validator->errors()->add(
                    'work_date',
                    'You already have a pending or approved half-day for this date and period.',
                );
            }
        });
    }
}
