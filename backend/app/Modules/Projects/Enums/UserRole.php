<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

/**
 * Project role helper. Ported from PMS's App\Core\Enums\UserRole, but the case
 * *values* are now the platform's Spatie role names so `hasAnyRole([...])`
 * checks resolve against the shared roles table.
 *
 * Mapping (PMS -> platform per-system roles): manager (APM/PM tier) -> pm_apm,
 * Member -> pm_member. super_admin sits above every module and also counts as a
 * project manager — see the manager checks in ProjectPolicy / ProjectController.
 */
enum UserRole: string
{
    case APM = 'pm_apm';
    case MEMBER = 'pm_member';

    /** Spatie role names that count as a project "manager" (PMS management tier). */
    public static function managerRoles(): array
    {
        return ['pm_apm', 'super_admin'];
    }
}
