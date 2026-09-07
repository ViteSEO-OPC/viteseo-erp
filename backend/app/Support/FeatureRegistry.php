<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The Feature Capability Registry — the single source of truth for every
 * function a customer can avail, which module it belongs to, what it depends on
 * (`requires`), and (later) its behaviour variants.
 *
 * An org "picks functions" from here at signup; the Entitlements service stores
 * the picks and auto-includes each function's dependencies. Pods will extend
 * this with their own functions during the per-pod variant/function audit.
 *
 * Grain rule: a function is a meaningful capability (e.g. "Invoicing"), NOT a
 * single button. Keep dependencies as simple `requires` links, not rules.
 */
class FeatureRegistry
{
    /**
     * @return array<string, array{label: string, module: string, requires: list<string>, variants: list<string>}>
     */
    public static function all(): array
    {
        return [
            // --- Module-level functions (coarse toggles) -------------------
            'hr' => ['label' => 'HR — TeamOra', 'module' => 'hr', 'requires' => [], 'variants' => []],
            'crm' => ['label' => 'CRM — Zerem', 'module' => 'crm', 'requires' => [], 'variants' => []],
            'pms' => ['label' => 'Projects — Sprintly', 'module' => 'pms', 'requires' => [], 'variants' => []],
            // No product name yet, unlike the other three. The build this came from
            // calls it Cashflo, which is that company's brand, not this one's.
            'accounting' => ['label' => 'Accounting', 'module' => 'accounting', 'requires' => [], 'variants' => []],

            /*
             * ── The sub-functions ViteSEO sells, and grants to roles ──────────
             *
             * Ported from kps on 2026-09-03. This repo carried only the three
             * top-level module keys, which made every tier of HRIS/CRM/PMS
             * identical: nothing below the module could be availed, priced or
             * granted. These keys are what a plan tier BUNDLES and what an org's
             * super admin may hand to a custom role -- so without them, "pick the
             * functionalities for this role" has nothing to pick from.
             *
             * `requires` is the dependency the Entitlements resolver walks, so
             * granting `crm.automation` implies `crm` and `crm.outreach` and the
             * caller never has to know that.
             */

            // --- HR sub-functions -------------------------------------------
            'hr.recruitment' => ['label' => 'Recruitment & candidate pipeline', 'module' => 'hr', 'requires' => ['hr'], 'variants' => []],
            'hr.payroll' => ['label' => 'PH payroll (SSS/PhilHealth/Pag-IBIG/BIR)', 'module' => 'hr', 'requires' => ['hr'], 'variants' => []],
            'hr.benefits' => ['label' => 'Benefit programs, allowances & loans', 'module' => 'hr', 'requires' => ['hr'], 'variants' => []],
            'hr.expenses' => ['label' => 'Reimbursements & office expenses', 'module' => 'hr', 'requires' => ['hr'], 'variants' => []],

            // --- CRM sub-functions ------------------------------------------
            'crm.nearby' => ['label' => 'Nearby Clients prospect discovery', 'module' => 'crm', 'requires' => ['crm'], 'variants' => []],
            'crm.outreach' => ['label' => 'Email outreach & inbound replies', 'module' => 'crm', 'requires' => ['crm'], 'variants' => []],
            'crm.performance' => ['label' => 'Performance dashboards, KPIs & BD ranking', 'module' => 'crm', 'requires' => ['crm'], 'variants' => []],
            // Full-tier exclusive -- deliberately absent from ADDON_PRICES so the
            // Full bundle can never be replicated a-la-carte.
            'crm.automation' => ['label' => 'Outreach automation (n8n)', 'module' => 'crm', 'requires' => ['crm', 'crm.outreach'], 'variants' => []],

            // --- Projects sub-functions -------------------------------------
            // --- Accounting sub-functions ------------------------------------
            'accounting.invoicing' => ['label' => 'Invoicing (AR)', 'module' => 'accounting', 'requires' => ['accounting'], 'variants' => []],
            'accounting.bills' => ['label' => 'Bills (AP)', 'module' => 'accounting', 'requires' => ['accounting'], 'variants' => []],
            'accounting.tax_vat' => ['label' => 'VAT / BIR reporting', 'module' => 'accounting', 'requires' => ['accounting'], 'variants' => []],
            'accounting.budgeting' => ['label' => 'Budget vs Actual', 'module' => 'accounting', 'requires' => ['accounting'], 'variants' => []],
            // Posts payroll to the ledger. Works standalone from a CSV, so it depends
            // only on accounting -- an org can buy this without buying the HRIS.
            'accounting.payroll_posting' => ['label' => 'Payroll → Journal posting', 'module' => 'accounting', 'requires' => ['accounting'], 'variants' => []],

            'pms.reports' => ['label' => 'Analysis & reports', 'module' => 'pms', 'requires' => ['pms'], 'variants' => []],
            'pms.workload' => ['label' => 'Workload & capacity planning', 'module' => 'pms', 'requires' => ['pms'], 'variants' => []],
            // Full-tier exclusive, same reason as crm.automation.
            'pms.import' => ['label' => 'AI-assisted bulk import', 'module' => 'pms', 'requires' => ['pms'], 'variants' => []],

            // --- Inventory sub-functions ------------------------------------
            // Full-tier exclusives — deliberately absent from ADDON_PRICES so the
            // only way to get them is the Full plan (they justify its bundle price).

            // --- Accounting sub-functions ----------------------------------
            // Posts payroll to the ledger — works standalone via CSV import, so it
            // depends only on accounting. (HRIS auto-sync is a separate optional add-on.)

            // --- Booking (appointments / rentals / classes) -----------------
            // Removes the "Powered by Kiplo Booking" footer on the public page.
        ];
    }

    /** @return list<string> All known feature keys. */
    public static function keys(): array
    {
        return array_keys(static::all());
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, static::all());
    }

    /** @return list<string> Direct dependencies of a feature. */
    public static function requires(string $key): array
    {
        return static::all()[$key]['requires'] ?? [];
    }

    public static function label(string $key): ?string
    {
        return static::all()[$key]['label'] ?? null;
    }

    /** @return list<string> Distinct module keys in the registry. */
    public static function modules(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $f): string => $f['module'],
            static::all(),
        )));
    }
}
