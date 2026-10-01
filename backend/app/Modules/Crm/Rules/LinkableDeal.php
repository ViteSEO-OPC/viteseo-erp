<?php

namespace App\Modules\Crm\Rules;

use App\Models\User;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Policies\LeadPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Planner event's `deal_id` must be a deal on a lead the caller may link work to (CEO audit crm-04).
 *
 * A deal has no visibility of its own; it is visible through its lead. So the deal and its lead are both
 * loaded through Eloquent (org-scoped, archived rows excluded) and the lead is judged by
 * {@see LeadPolicy::link()}, exactly as a `lead_id` is. It replaces a bare
 * `exists:crm_deals,id`, which accepted any deal in the table.
 */
class LinkableDeal implements ValidationRule
{
    public const MESSAGE = 'The selected deal is not available to you.';

    /** @param  mixed  $eventOwnerId  the BD the event will belong to (used only for the automation account) */
    public function __construct(
        private readonly ?User $user,
        private readonly mixed $eventOwnerId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return; // nullability and the integer type are governed by the field's other rules
        }

        $lead = Deal::query()->find($value)?->lead;

        if ($this->user === null || $lead === null || ! $this->user->can('link', [$lead, LinkableLead::ownerId($this->eventOwnerId)])) {
            $fail(self::MESSAGE);
        }
    }
}
