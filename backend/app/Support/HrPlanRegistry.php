<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Subscription tiers for the standalone HRIS (TeamOra) product. Prices are PHP /
 * month, PH-local-anchored per the 2026-08 pricing decisions (Julius + Claude):
 * flat per-org against per-EMPLOYEE competitors (Sprout/Salarium-class tools run
 * ₱50–150 per employee per month, so a 20-head org pays ₱1,000–3,000 there),
 * and every bundle prices at-or-below its Custom + add-ons sum. `features` are
 * FeatureRegistry keys; dependencies auto-resolved by Entitlements.
 *
 * 2026-08-19 restructure ("Proposal B"): benefits/loans and expenses split out
 * of core into their own add-ons (`hr.benefits`, `hr.expenses`), a new Pro tier
 * lands between Basic and Full, and Full now bundles all four add-ons.
 *
 * Arbitrage check — no bundle may cost more than Custom + its add-ons (or than
 * a smaller bundle + the difference), so à-la-carte never undercuts a bundle:
 *   basic  999   ≤ custom + recruitment                     = 699 + 400            = 1,099 ✓
 *   pro    1,599 ≤ custom + payroll + benefits              = 699 + 900 + 300      = 1,899 ✓
 *   full   1,899 ≤ custom + all four add-ons                = 699 + 1,800          = 2,499 ✓
 *   full   1,899 ≤ pro + recruitment + expenses             = 1,599 + 400 + 200    = 2,199 ✓
 *   full   1,899 ≤ basic + payroll + benefits + expenses    = 999 + 900 + 300 + 200 = 2,399 ✓
 * HrPlanArbitrageTest enforces this for every bundle.
 */
class HrPlanRegistry
{
    /**
     * What the CORE HR engine includes on EVERY tier (custom included).
     * Rendered on the onboarding cards so "Custom" never looks empty — it's the
     * full core, with extras à la carte. Keep to short seller-facing lines.
     *
     * @return list<string>
     */
    public static function coreHighlights(): array
    {
        return [
            '201 employee files & documents',
            'Attendance with geofence & selfie check-in',
            'Leave, overtime & request approvals',
            'Departments, holidays & work schedules',
            'Anonymous concerns & FAQ knowledge base',
            'HR reports & audit trail',
        ];
    }

    /**
     * @return array<string, array{label: string, price: float, blurb: string, features: list<string>}>
     */
    public static function all(): array
    {
        return [
            // Core HR + recruitment (candidate pipeline + hiring). Cheaper than
            // custom + recruitment (₱1,099).
            'basic' => [
                'label' => 'Basic (HR Core)',
                'price' => 999.0,
                'blurb' => '+ Recruitment & candidate pipeline',
                'features' => ['hr', 'hr.recruitment'],
            ],
            // The complete payroll shop: full PH payroll (SSS/PhilHealth/
            // Pag-IBIG/BIR computations, payslips, bank files) PLUS benefit
            // programs, allowances & loans — but deliberately NO recruitment.
            // Below the custom-sum ceiling (₱699 + 900 + 300 = ₱1,899).
            'pro' => [
                'label' => 'Pro (Payroll)',
                'price' => 1599.0,
                'blurb' => '+ PH payroll with benefits & loans — the complete payroll shop',
                'features' => ['hr', 'hr.payroll', 'hr.benefits'],
            ],
            // Everything: recruitment, payroll, benefits/loans AND expense
            // management (reimbursements + office expenses). Below every
            // custom/bundle-sum ceiling — see the arbitrage table above.
            'full' => [
                'label' => 'Full (Kumpleto)',
                'price' => 1899.0,
                'blurb' => 'Everything — recruitment, payroll, benefits, expenses',
                'features' => ['hr', 'hr.recruitment', 'hr.payroll', 'hr.benefits', 'hr.expenses'],
            ],
            // Pure à-la-carte: employees, attendance, leave, self-service at
            // the base.
            'custom' => [
                'label' => 'Custom (Ikaw Bahala)',
                'price' => 699.0,
                'blurb' => 'Complete core HR — records, attendance, leave — add functions as you need them',
                'features' => ['hr'],
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(static::all());
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, static::all());
    }

    /** @return array{label: string, price: float, blurb: string, features: list<string>}|null */
    public static function get(string $key): ?array
    {
        return static::all()[$key] ?? null;
    }

    /**
     * Per-function add-on prices (PHP / month) — availed on top of a tier.
     *
     * @var array<string, float>
     */
    public const ADDON_PRICES = [
        'hr.recruitment' => 400.0,
        'hr.payroll' => 900.0,
        'hr.benefits' => 300.0,
        'hr.expenses' => 200.0,
    ];

    /**
     * Monthly price = tier base + any availed add-on features NOT already
     * included in the tier.
     *
     * @param  list<string>  $features
     */
    public static function priceFor(string $planKey, array $features): float
    {
        $plan = static::get($planKey) ?? static::get('basic');
        $total = $plan['price'];

        foreach ($features as $f) {
            if (! in_array($f, $plan['features'], true) && isset(self::ADDON_PRICES[$f])) {
                $total += self::ADDON_PRICES[$f];
            }
        }

        return round($total, 2);
    }
}
