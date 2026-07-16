<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

/**
 * Lifecycle state of a project. A "done" project is treated as completed: its
 * tasks drop out of the active task views (All Tasks / My Tasks / board).
 */
enum ProjectState: string
{
    case ACTIVE = 'active';
    case DONE = 'done';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::DONE => 'Done',
        };
    }
}
