<?php

declare(strict_types=1);

namespace App\Modules\Crm\Enums;

/**
 * Email Outreach Status — the COMMUNICATION lifecycle of a lead's email thread (`leads.email_outreach`).
 * Distinct from the Lead Status / sales lifecycle (`leads.status`), which stays a manual BD decision:
 * inbound email advances this column only (see InboundReplyProcessor).
 *
 * `NONE` is the default — no outreach email has been sent yet.
 *
 * This enum is the single backend source of truth for the accepted values. Every request that reads or
 * writes the column validates against it (Index/Store/UpdateLeadRequest) rather than restating the list,
 * so the read filter and the write path can never diverge.
 *
 * The column is a plain string in the database, not a DB-level enum, and no response payload exposes
 * this type — the API continues to return the raw string value.
 */
enum LeadEmailOutreach: string
{
    case SENT = 'sent';
    case OPENED = 'opened';
    case REPLIED = 'replied';
    case NONE = 'none';

    public function label(): string
    {
        return match ($this) {
            self::SENT => 'Sent',
            self::OPENED => 'Opened',
            self::REPLIED => 'Replied',
            self::NONE => 'None',
        };
    }
}
