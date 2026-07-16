<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

enum StatusKey: string
{
    case TODO = 'todo';
    case IN_PROGRESS = 'in_progress';
    case DONE = 'done';
    case CUSTOM = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::TODO => 'To Do',
            self::IN_PROGRESS => 'In Progress',
            self::DONE => 'Done',
            self::CUSTOM => 'Custom',
        };
    }

    /**
     * The 3 protected default statuses seeded for every project.
     *
     * @return array<int, self>
     */
    public static function defaults(): array
    {
        return [self::TODO, self::IN_PROGRESS, self::DONE];
    }
}
