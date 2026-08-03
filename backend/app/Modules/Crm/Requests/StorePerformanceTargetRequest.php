<?php

namespace App\Modules\Crm\Requests;

use App\Models\User;
use App\Modules\Crm\Enums\PerformanceTargetMetric;
use App\Modules\Crm\Enums\PerformanceTargetPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating a performance target. Authorization is the route's `crm.performance.manage` permission
 * (Admin + Super Admin); this class validates that the target describes something real.
 */
class StorePerformanceTargetRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'period_type' => ['required', Rule::in(PerformanceTargetPeriod::values())],
            'period_start' => ['required', 'date'],
            'metric' => ['required', Rule::in(PerformanceTargetMetric::values())],
            // 0 is allowed: "explicitly no goal this period" is a real management statement and
            // differs from having configured nothing at all.
            'target_value' => ['required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $userId = $this->input('user_id');

            // A target belongs to a Business Development Manager — the only role whose performance
            // these surfaces report. Mirrors PerformanceScopeRequest's report-subject rule.
            if ($userId !== null && ! $this->isActiveBd((int) $userId)) {
                $validator->errors()->add('user_id', 'A performance target must belong to an active Business Development Manager.');
            }
        });
    }

    private function isActiveBd(int $userId): bool
    {
        return User::query()
            ->whereKey($userId)
            ->where('status', 'active')
            ->whereHas('roles', fn (Builder $query) => $query->where('name', 'crm_bd'))
            ->exists();
    }
}
