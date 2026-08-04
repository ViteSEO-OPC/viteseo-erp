<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Updating a performance target.
 *
 * Only the value and the lifecycle flag are mutable. Subject, period and metric ARE the target's
 * identity — editing them would silently move a goal to a different BD or quarter and orphan the
 * activity trail that recorded it. Retire the target and set another instead.
 */
class UpdatePerformanceTargetRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'target_value' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
