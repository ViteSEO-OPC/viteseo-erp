<?php

declare(strict_types=1);

namespace App\Modules\Crm\Enums;

/**
 * Lifecycle state of a call attempt. A call opens `in_progress` (the BD is on the widget) and
 * transitions to `completed` when the session is concluded and dispositioned. The transition is
 * owned by CallSessionService (orchestration) — this enum only names the states, it never moves
 * between them.
 */
enum CallStatus: string
{
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::IN_PROGRESS => 'In Progress',
            self::COMPLETED => 'Completed',
        };
    }
}
