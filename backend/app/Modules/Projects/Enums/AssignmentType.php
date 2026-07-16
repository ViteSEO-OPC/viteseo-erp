<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

enum AssignmentType: string
{
    case GROUP = 'group';
    case INDIVIDUAL = 'individual';

    public function label(): string
    {
        return match ($this) {
            self::GROUP => 'Group',
            self::INDIVIDUAL => 'Individual',
        };
    }
}
