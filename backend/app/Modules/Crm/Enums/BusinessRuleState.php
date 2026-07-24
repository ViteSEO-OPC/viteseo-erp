<?php

declare(strict_types=1);

namespace App\Modules\Crm\Enums;

/**
 * Lifecycle state of a Business Rule version (Implementation Design §1.0 / §1.4). A version is created as a
 * `draft`, becomes `active` on publish (the single source of truth), and is `archived` when a newer version
 * is published — archived versions are permanent history and are never re-activated in place (rollback is
 * forward-only, §6b). The invariant "at most one active and at most one draft per module" is enforced by
 * BusinessRuleService, not by this enum.
 */
enum BusinessRuleState: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::ACTIVE => 'Active',
            self::ARCHIVED => 'Archived',
        };
    }
}
