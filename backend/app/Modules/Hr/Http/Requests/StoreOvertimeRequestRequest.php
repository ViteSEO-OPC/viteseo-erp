<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Models\OvertimeRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreOvertimeRequestRequest extends FormRequest
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
            'hours' => ['required', 'numeric', 'min:0.25', 'max:16'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /** One live overtime request per date: block a duplicate while pending or approved. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $employeeId = $this->user()?->employee?->id;
            if (! $employeeId) {
                return;
            }

            $duplicate = OvertimeRequest::where('employee_id', $employeeId)
                ->where('work_date', $this->input('work_date'))
                ->whereIn('status', ['pending', 'approved'])
                ->exists();

            if ($duplicate) {
                $validator->errors()->add(
                    'work_date',
                    'You already have a pending or approved overtime request for this date.',
                );
            }
        });
    }
}
