<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The Cross-Module Connection Registry — the single source of truth for every
 * data-sharing "integration" between two availed systems. A connection lights up
 * (becomes AVAILABLE) only when an org has availed BOTH sides, i.e. every feature
 * key in `requires` is entitled. This is platform-level and cross-cutting: it is
 * owned by no single module.
 *
 * `status` = 'available' means shippable today; 'coming_soon' is registered so the
 * Integrations page can advertise it, but it can never be toggled on.
 */
class ConnectionRegistry
{
    /**
     * connection_key => metadata.
     *
     * Empty on purpose: none of ViteSEO's four systems (hr, crm, pms, kiosk)
     * shares data with another one yet, so there is no bridge to register. Add an
     * entry the moment one actually ships — the Integrations page, the
     * `connection:<key>` middleware and the sidebar all read this and need no
     * other change.
     *
     * @return array<string, array{label: string, description: string, from: string, to: string, requires: list<string>, status: string}>
     */
    public static function all(): array
    {
        return [
            'payroll_accounting' => [
                'label' => 'Payroll → Accounting',
                'description' => 'Post finalized payroll runs straight to the accounting ledger as balanced journal entries.',
                'from' => 'hr',
                'to' => 'accounting',
                'requires' => ['hr', 'accounting.payroll_posting'],
                'status' => 'available',
            ],
        ];
    }

    /** @return list<string> All known connection keys. */
    public static function keys(): array
    {
        return array_keys(static::all());
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, static::all());
    }

    /**
     * @return array{label: string, description: string, from: string, to: string, requires: list<string>, status: string}|null
     */
    public static function get(string $key): ?array
    {
        return static::all()[$key] ?? null;
    }
}
