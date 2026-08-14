<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the Planner list. Authorization is NOT here — the route already gates on
 * `viewAny` and PlannerEvent::scopeVisibleTo bounds the rows; this only validates shape.
 *
 * `assigned_to` is deliberately validated but NOT role-restricted. A BD may send it and the
 * request will pass — `scopeVisibleTo` still narrows to their own events first, so the filter
 * can only ever intersect what they may already see (another BD's id yields an empty list, and
 * discloses nothing about whether that BD has work). One ownership boundary, enforced in one
 * place, rather than a second rule here that could drift away from the first.
 */
class IndexPlannerEventRequest extends FormRequest
{
    /** The alternate representation of the list. Absent = the live Planner (Calendar + Board). */
    private const VIEWS = ['history'];

    private const STATUSES = ['todo', 'scheduled', 'in_progress', 'completed'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'view' => ['sometimes', 'nullable', Rule::in(self::VIEWS)],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'nullable', Rule::in(self::STATUSES)],
            'event_type' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /** True when the caller asked for History rather than the live Planner. */
    public function wantsHistory(): bool
    {
        return $this->input('view') === 'history';
    }
}
