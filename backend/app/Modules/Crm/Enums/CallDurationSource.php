<?php

declare(strict_types=1);

namespace App\Modules\Crm\Enums;

/**
 * WHO MEASURED a call's `duration_seconds` — the CRM, or the carrier.
 *
 * The transport (`crm_calls.provider`) cannot answer this, which is why the distinction has its own
 * value. A Twilio call whose completion webhook never arrived — a tunnel down in development, a
 * provider outage, a BD who concluded the record by hand before the callback landed — is carried by
 * Twilio and yet timed by the CRM.
 *
 * The distinction is honesty about a number, not a business rule. Nothing in the scoring engine reads
 * duration today: calls are scored from the lead's `cold_calling` status, and this enum adds a fact
 * for a rule that may be configured later, never a rule of its own.
 */
enum CallDurationSource: string
{
    /**
     * The CRM's own start→end measurement of how long the call RECORD was open. It is not talk time
     * and must never be presented as though it were: under manual calling the conversation happens on
     * a handset the CRM cannot observe.
     */
    case MANUAL = 'manual';

    /** The carrier's measurement of the dialed leg — real, verified talk time. */
    case PROVIDER = 'provider';

    public function label(): string
    {
        return match ($this) {
            self::MANUAL => 'Logged in the CRM',
            self::PROVIDER => 'Measured by the carrier',
        };
    }

    /** Whether this duration is a carrier's verified talk time rather than the CRM's own clock. */
    public function isVerified(): bool
    {
        return $this === self::PROVIDER;
    }
}
