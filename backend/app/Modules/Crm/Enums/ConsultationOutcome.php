<?php

declare(strict_types=1);

namespace App\Modules\Crm\Enums;

/**
 * 🔒 FROZEN business vocabulary for the result of a concluded consultation (PO decision 2026-07-24,
 * PDR-002 §4b). Stored in `crm_planner_events.outcome`; null until the consultation concludes.
 *
 * This is BUSINESS data and is never merged into the Planner `status` lifecycle, which stays
 * `todo · scheduled · in_progress · completed`. `completed` means "concluded", not "went well" — a
 * cancelled consultation is a *completed* event carrying the `cancelled` outcome, exactly as a
 * cancelled call is already recorded on `crm_calls`.
 *
 * Deliberately EXCLUDED:
 * - **rescheduled** — not a result. Rescheduling is a Planner scheduling action (editing `starts_at`);
 *   the event stays pending and concludes later with a real outcome.
 * - **follow-up required** — not a result. A follow-up is a subsequent Planner activity in its own right.
 *
 * {@see attended()} is the single source of truth for the Successful Consultations KPI: it counts
 * unique leads whose consultation the lead actually turned up to. Never re-derive that grouping in a
 * query — ask this enum.
 */
enum ConsultationOutcome: string
{
    /** Held; the lead engaged positively. */
    case SUCCESSFUL = 'successful';

    /** Held — the lead attended and heard the pitch — but declined. Still a consultation that happened. */
    case NOT_INTERESTED = 'not_interested';

    /** The lead did not turn up. No consultation took place. */
    case NO_SHOW = 'no_show';

    /** Called off before it happened. No consultation took place. */
    case CANCELLED = 'cancelled';

    /** BD-facing label. */
    public function label(): string
    {
        return match ($this) {
            self::SUCCESSFUL => 'Successful',
            self::NOT_INTERESTED => 'Not Interested',
            self::NO_SHOW => 'No Show',
            self::CANCELLED => 'Cancelled',
        };
    }

    /**
     * Did the lead actually undergo the consultation? This — not `status` alone — is what the KPI keys
     * on, because a no-show marked `completed` would otherwise be counted as a consultation held.
     *
     * `not_interested` counts: the lead attended and heard the pitch. Declining the offer is a sales
     * result, not an absence.
     */
    public function attended(): bool
    {
        return match ($this) {
            self::SUCCESSFUL, self::NOT_INTERESTED => true,
            self::NO_SHOW, self::CANCELLED => false,
        };
    }

    /** @return list<string> every valid stored value — the authority for validation rules. */
    public static function values(): array
    {
        return array_map(fn (self $c): string => $c->value, self::cases());
    }

    /** @return list<string> the values that count toward the Successful Consultations KPI. */
    public static function attendedValues(): array
    {
        return array_values(array_map(
            fn (self $c): string => $c->value,
            array_filter(self::cases(), fn (self $c): bool => $c->attended()),
        ));
    }
}
