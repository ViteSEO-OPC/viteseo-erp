<?php

namespace App\Modules\Crm\Rules;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Policies\LeadPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Planner event's `lead_id` must be a lead the caller may link work to (CEO audit crm-04).
 *
 * Who may link is decided by {@see LeadPolicy::link()}; this rule only adapts that
 * ability to validation. It replaces a bare `exists:crm_leads,id`, which queried the table directly and so
 * accepted a teammate's lead, an archived lead and another organization's lead alike.
 *
 *  - The lead is loaded through Eloquent, so the BelongsToOrg scope and soft deletes apply: another
 *    organization's lead and an archived lead are simply not found.
 *  - Not found and not allowed fail with the SAME message, so the response reveals neither whether an id
 *    exists nor who owns it.
 */
class LinkableLead implements ValidationRule
{
    public const MESSAGE = 'The selected lead is not available to you.';

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

        $lead = Lead::query()->find($value);

        if ($this->user === null || $lead === null || ! $this->user->can('link', [$lead, self::ownerId($this->eventOwnerId)])) {
            $fail(self::MESSAGE);
        }
    }

    public static function ownerId(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
