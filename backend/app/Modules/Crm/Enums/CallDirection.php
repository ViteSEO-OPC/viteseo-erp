<?php

declare(strict_types=1);

namespace App\Modules\Crm\Enums;

/**
 * Direction of a call relative to the CRM. Outbound (BD dials the prospect) is the only P1 flow;
 * inbound is reserved for future provider transports that receive calls. Kept as a bounded enum
 * rather than a free-form string so the domain stays closed — unlike `provider`, which is
 * deliberately an open string to admit new vendors without a code change.
 */
enum CallDirection: string
{
    case OUTBOUND = 'outbound';
    case INBOUND = 'inbound';

    public function label(): string
    {
        return match ($this) {
            self::OUTBOUND => 'Outbound',
            self::INBOUND => 'Inbound',
        };
    }
}
