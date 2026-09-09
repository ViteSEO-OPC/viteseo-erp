<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The roles the HR hire form may hand out, and the only ones.
 *
 * Hiring someone used to give them `hr_employee` and nothing else, so a new hire
 * who was there to work on projects had a login and nowhere to go until an admin
 * noticed and granted it from another module's Team screen. The grant now happens
 * on the form HR is already filling in — but that form is reachable by
 * `hr_officer`, who has no business minting a platform owner or another module's
 * admin, so what it may grant is a closed list rather than "any role".
 *
 * WHERE THE LINE IS. A role is grantable at hire if a new hire could reasonably
 * need it on day one for the job they were hired to do AND it cannot irreversibly
 * rewrite records. That keeps the worker tiers and the project MANAGER (pm_apm is
 * a job title people are hired into), and it keeps out every *_admin — the
 * administrator of a module's configuration, which is granted by someone who
 * already holds that authority, from the module's own screen where the blast
 * radius is visible. acc_admin is the sharpest case: reversing a journal or
 * deleting an account rewrites recorded financial history, and access to it is a
 * decision that belongs with the books, not the hire form.
 *
 * HR's own roles are deliberately absent. `hr_employee` is assigned to everyone by
 * EmployeeService::create(), and `hr_officer` is granted through the management
 * toggle on the employee record, which is where that decision belongs.
 *
 * Removal is not here either, and that is the same rule
 * {@see \App\Modules\Crm\Services\CrmAccessService} keeps: this only ever ADDS.
 * Taking access away stays in each module's Team screen, where the admin doing it
 * can see what they are removing.
 */
final class GrantableRoles
{
    /**
     * Module feature key => its roles, NARROWEST FIRST.
     *
     * The order is load-bearing: a trainee may only ever hold element zero.
     *
     * @var array<string, list<string>>
     */
    public const BY_MODULE = [
        'pms' => ['pm_member', 'pm_apm'],
        'crm' => ['crm_bd'],
        'accounting' => ['acc_viewer', 'acc_accountant'],
    ];

    /**
     * Employment statuses whose tier is not a choice.
     *
     * A trainee is on the tools to learn them, and every role above the narrowest
     * can delete a project, approve a task or rewrite a ledger. The form may ask
     * for more; it does not get it. See EmployeeService::STATUSES for the full set.
     *
     * @var list<string>
     */
    public const NARROW_STATUSES = ['ojt', 'part_time', 'project_based'];

    /** Every grantable role, flat. */
    public static function all(): array
    {
        return array_merge(...array_values(self::BY_MODULE));
    }

    /** The module a grantable role belongs to, or null when it is not grantable. */
    public static function moduleFor(string $role): ?string
    {
        foreach (self::BY_MODULE as $module => $roles) {
            if (in_array($role, $roles, true)) {
                return $module;
            }
        }

        return null;
    }

    /** The one role a trainee may hold in this module. */
    public static function narrowestFor(string $module): ?string
    {
        return self::BY_MODULE[$module][0] ?? null;
    }

    /** Is this employment status capped at the narrowest tier? */
    public static function isNarrow(?string $status): bool
    {
        return $status !== null && in_array($status, self::NARROW_STATUSES, true);
    }
}
