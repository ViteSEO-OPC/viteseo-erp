<?php

declare(strict_types=1);

namespace App\Modules\Crm\Enums;

/**
 * Cold Calling Status — the outbound-call lifecycle of a lead (`leads.cold_calling`). Independent of
 * both the Email Outreach Status {@see LeadEmailOutreach} and the Lead Status / sales lifecycle.
 *
 * `NOT_CALLED` is the default. Note the hyphenated backing value (`not-called`): it is the value already
 * stored in the database and returned by the API, so it is preserved exactly.
 *
 * This enum is the single backend source of truth for the accepted values. Every request that reads or
 * writes the column validates against it (Index/Store/UpdateLeadRequest) rather than restating the list.
 *
 * The column is a plain string in the database, not a DB-level enum, and no response payload exposes
 * this type — the API continues to return the raw string value.
 */
enum LeadColdCalling: string
{
    case CALLED = 'called';
    case SCHEDULED = 'scheduled';
    case NOT_CALLED = 'not-called';

    public function label(): string
    {
        return match ($this) {
            self::CALLED => 'Called',
            self::SCHEDULED => 'Scheduled',
            self::NOT_CALLED => 'Not Called',
        };
    }
}
