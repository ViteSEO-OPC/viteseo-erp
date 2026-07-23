<?php

declare(strict_types=1);

namespace App\Modules\Crm\Enums;

/**
 * Disposition of a concluded call — the outcome a BD selects when ending the session. Null while
 * the call is still `in_progress`; set exactly once when it is `completed`. `connected` is the only
 * outcome that reached the prospect; the rest are unsuccessful attempts (the workflow meaning of
 * each — e.g. whether the Planner Event stays open for a retry — is decided by CallSessionService,
 * not here).
 */
enum CallOutcome: string
{
    case CONNECTED = 'connected';
    case NO_ANSWER = 'no_answer';
    case BUSY = 'busy';
    case VOICEMAIL = 'voicemail';
    case CANCELLED = 'cancelled';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::CONNECTED => 'Connected',
            self::NO_ANSWER => 'No Answer',
            self::BUSY => 'Busy',
            self::VOICEMAIL => 'Voicemail',
            self::CANCELLED => 'Cancelled',
            self::FAILED => 'Failed',
        };
    }
}
