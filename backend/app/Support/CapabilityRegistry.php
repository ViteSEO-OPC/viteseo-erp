<?php

declare(strict_types=1);

namespace App\Support;

use Spatie\Permission\Models\Permission;

/**
 * Which capabilities an org may hand to a custom role, and which it may not.
 *
 * A client's own super admin builds roles by ticking capabilities. The rule Julius
 * set is that the tick list is NOT the whole platform -- it is bounded by what that
 * org actually availed. An org on CRM Basic must not be able to mint a role that
 * grants email outreach, because it did not buy email outreach; and it must not be
 * able to grant anything at all in HR if it never availed HR.
 *
 * TWO AXES, AND THEY ARE NOT THE SAME AXIS. A permission lives in a PERMISSION
 * namespace (`projects.tasks.update`); a plan sells a FEATURE key (`pms.workload`).
 * Projects is the case that proves they differ -- its permissions say `projects.*`
 * and its feature says `pms.*` -- so the two are mapped explicitly here rather than
 * inferred from a shared prefix that only happens to line up four times out of six.
 *
 * WHAT IS DERIVED AND WHAT IS DECLARED. The capability list itself is derived: it is
 * whatever Spatie permissions actually exist, so a module that adds a permission
 * gets it in the builder without touching this file. What is declared is the small
 * map from a permission GROUP to the sub-feature that sells it, because that
 * relationship exists nowhere in the schema -- it lives in the route middleware, and
 * this file is the second place it is written down. Every entry below was read off
 * a `feature:` gate on a real route, not guessed.
 *
 * A group with no entry belongs to its module's CORE feature, which every tier of
 * that module includes. That default is the safe direction: the worst case is a
 * capability offered on a tier that could arguably have withheld it, never a
 * capability withheld from a tier that paid for it.
 */
final class CapabilityRegistry
{
    /**
     * Permission namespace => the module's CORE feature key.
     *
     * @var array<string, string>
     */
    private const MODULE_FEATURE = [
        'hr' => 'hr',
        'crm' => 'crm',
        // The one that proves the two axes are different.
        'projects' => 'pms',
        'kiosk' => 'kiosk',
    ];

    /**
     * `<namespace>.<group>` => the sub-feature that sells it.
     *
     * Read off the `feature:` route middleware, not invented. Anything absent
     * belongs to the module core.
     *
     * @var array<string, string>
     */
    private const GROUP_FEATURE = [
        // HR
        'hr.recruitment' => 'hr.recruitment',
        'hr.payroll' => 'hr.payroll',
        'hr.payroll_inquiries' => 'hr.payroll',
        'hr.benefits' => 'hr.benefits',
        'hr.office_expenses' => 'hr.expenses',
        'hr.reimbursements' => 'hr.expenses',

        // CRM
        'crm.outreach' => 'crm.outreach',
        'crm.performance' => 'crm.performance',

        // Projects — permission namespace `projects`, feature namespace `pms`.
        'projects.workload' => 'pms.workload',
    ];

    /**
     * Every capability on the platform, grouped by module.
     *
     * @return list<array{module: string, feature: string, key: string, group: string, label: string}>
     */
    public static function all(): array
    {
        $out = [];

        foreach (Permission::query()->orderBy('name')->pluck('name') as $name) {
            $parts = explode('.', $name);
            $namespace = $parts[0];

            if (! isset(self::MODULE_FEATURE[$namespace])) {
                continue; // a permission outside any sellable module
            }

            // The feature a capability is sold under is keyed on the first segment
            // after the module, and GROUP_FEATURE is written that way.
            $featureGroup = $parts[1] ?? '';

            // The subject it acts on is every segment between the module and the
            // action, which is already how label() reads the name. Grouping on the
            // first segment alone collapsed `lead.notes` and `lead.attachments`
            // into one "Lead" block whose checkboxes read "create, delete, create,
            // delete" -- four different capabilities under two names, with nothing
            // on screen to tell an admin which was which.
            $group = count($parts) > 2
                ? implode('_', array_slice($parts, 1, -1))
                : $featureGroup;

            $out[] = [
                'module' => $namespace,
                'feature' => self::GROUP_FEATURE[$namespace.'.'.$featureGroup] ?? self::MODULE_FEATURE[$namespace],
                'key' => $name,
                'group' => $group,
                'label' => self::label($name),
            ];
        }

        return $out;
    }

    /**
     * The capabilities THIS org may grant — the whole catalog, minus everything it
     * did not avail.
     *
     * @return list<array{module: string, feature: string, key: string, group: string, label: string}>
     */
    public static function forOrg(int $orgId): array
    {
        $entitled = Entitlements::keysFor($orgId);

        return array_values(array_filter(
            self::all(),
            static fn (array $c): bool => in_array($c['feature'], $entitled, true)
        ));
    }

    /**
     * The subset of $keys this org is NOT allowed to grant.
     *
     * The writer's guard. `forOrg` shapes the picker, but a picker is a suggestion:
     * the request can name any permission string it likes, and this is what refuses
     * one the org never bought.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function refusedFor(int $orgId, array $keys): array
    {
        $allowed = array_column(self::forOrg($orgId), 'key');

        return array_values(array_diff($keys, $allowed));
    }

    /** "hr.leave_requests.approve" -> "Leave requests: approve". */
    private static function label(string $name): string
    {
        $parts = explode('.', $name);
        array_shift($parts);

        $action = count($parts) > 1 ? array_pop($parts) : null;
        $subject = ucfirst(str_replace('_', ' ', implode(' ', $parts)));

        return $action === null ? $subject : $subject.': '.str_replace('_', ' ', $action);
    }
}
