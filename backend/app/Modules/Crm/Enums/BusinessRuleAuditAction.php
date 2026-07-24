<?php

declare(strict_types=1);

namespace App\Modules\Crm\Enums;

/**
 * The action recorded on an immutable Business Rule audit entry (Implementation Design §1.0 / §1.3).
 * Rule-level actions (`edit`, `enable`, `disable`) carry a `rule_key`; version-level actions (`seed`,
 * `reorder`, `publish`, `rollback`, `discard_draft`) leave it null. `create` and `delete` are reserved for a
 * future enhancement (adding/removing a signal is a structural computation change) and are NOT valid in v1.
 */
enum BusinessRuleAuditAction: string
{
    case SEED = 'seed';
    case EDIT = 'edit';
    case ENABLE = 'enable';
    case DISABLE = 'disable';
    case REORDER = 'reorder';
    case PUBLISH = 'publish';
    case ROLLBACK = 'rollback';
    case DISCARD_DRAFT = 'discard_draft';

    public function label(): string
    {
        return match ($this) {
            self::SEED => 'Seed',
            self::EDIT => 'Edit',
            self::ENABLE => 'Enable',
            self::DISABLE => 'Disable',
            self::REORDER => 'Reorder',
            self::PUBLISH => 'Publish',
            self::ROLLBACK => 'Rollback',
            self::DISCARD_DRAFT => 'Discard Draft',
        };
    }
}
