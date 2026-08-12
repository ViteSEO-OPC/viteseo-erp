<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

/**
 * Review state of a task/subtask. Everything a manager creates is APPROVED on
 * the spot; a member's proposal starts PENDING and only becomes real work once
 * a PM/Admin approves it. REJECTED rows stay with their author so they can be
 * revised and resubmitted.
 */
enum ApprovalStatus: string
{
    case APPROVED = 'approved';
    case PENDING = 'pending';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::APPROVED => 'Approved',
            self::PENDING => 'Pending approval',
            self::REJECTED => 'Rejected',
        };
    }
}
