<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Retuning the platform-wide workload bands.
 *
 * The bands must stay ordered and inside the range, or "heavy" stops meaning
 * anything: light_max < moderate_max < max_capacity. Authorization is the
 * controller's (`projects.workload.manage_capacity`).
 */
class UpdateWorkloadCapacityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'max_capacity' => ['required', 'integer', 'min:2', 'max:1000'],
            'light_max' => ['required', 'integer', 'min:1'],
            'moderate_max' => ['required', 'integer', 'min:2'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $max = (int) $this->input('max_capacity');
            $light = (int) $this->input('light_max');
            $moderate = (int) $this->input('moderate_max');

            if ($light >= $moderate) {
                $v->errors()->add('light_max', 'The Light band must end below where Moderate ends.');
            }

            if ($moderate >= $max) {
                $v->errors()->add('moderate_max', 'The Moderate band must end below the maximum capacity, or nothing is ever Heavy.');
            }
        });
    }
}
