<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\EmployeeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSharedLeaveAllotmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employment_status' => ['required', 'string', Rule::in(EmployeeService::STATUSES)],
            'days_per_year' => ['required', 'integer', 'min:0', 'max:365'],
        ];
    }
}
