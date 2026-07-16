<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

enum FlagReason: string
{
    case ASSIGNEE_LEFT_GROUP = 'assignee_left_group';
    case GROUP_REASSIGNED = 'group_reassigned';

    public function label(): string
    {
        return match ($this) {
            self::ASSIGNEE_LEFT_GROUP => 'Assignee left group',
            self::GROUP_REASSIGNED => 'Group reassigned',
        };
    }
}
