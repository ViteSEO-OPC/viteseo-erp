<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Subscription tiers for the standalone Accounting product. Prices are PHP /
 * month, PH-local anchored: the competitors are BIR-capable local tools — MPM
 * (₱358–896) and Oojeema (₱490–2,800) — not QuickBooks or Xero, which do not do
 * native BIR forms at all. `features` are FeatureRegistry keys; dependencies are
 * auto-resolved by Entitlements.
 *
 * The tier keys are `basic · pro · full · custom`, matching the other three
 * systems here. The build this module came from called the middle tier
 * "business"; four systems that name their tiers differently is a question an
 * operator has to answer on every onboarding screen, so it is `pro` here.
 *
 * Arbitrage check — no bundle may cost more than Custom + its add-ons, so
 * à-la-carte never undercuts a bundle:
 *   basic  599   ≤ custom + invoicing                    = 499 + 300              = 799   ✓
 *   pro    999   ≤ custom + invoicing + bills            = 499 + 300 + 300        = 1,099 ✓
 *   full   1,999 ≤ custom + all five add-ons             = 499 + 1,800            = 2,299 ✓
 *   full   1,999 ≤ pro + tax_vat + budgeting + payroll   = 999 + 400 + 300 + 500  = 2,199 ✓
 *   full   1,999 ≤ basic + bills + tax + budget + payroll = 599 + 1,500           = 2,099 ✓
 */
class AccountingPlanRegistry
{
    /**
     * What the CORE accounting engine includes on EVERY tier, custom included —
     * so "Custom" never reads as empty on an onboarding card. Seller-facing.
     *
     * @return list<string>
     */
    public static function coreHighlights(): array
    {
        return [
            'Chart of accounts & double-entry journals',
            'Bank, cash & manual entries with reversals',
            'Customers, suppliers & contact ledgers',
            'Trial balance, P&L and balance sheet',
            'Attachments on every entry',
            'Full audit trail',
        ];
    }

    /**
     * @return array<string, array{label: string, price: float, blurb: string, features: list<string>}>
     */
    public static function all(): array
    {
        return [
            // Core books + customer invoicing. Level with MPM Basic (₱448) and
            // Oojeema Lite (₱490), both of which cap transactions, and ₱200 below
            // buying the same two à la carte.
            'basic' => [
                'label' => 'Basic (Libro)',
                'price' => 599.0,
                'blurb' => 'The complete core books plus customer invoicing.',
                'features' => ['accounting', 'accounting.invoicing'],
            ],
            // Both sides of the money. Below Oojeema Pro (₱1,120) and below the
            // à-la-carte sum.
            'pro' => [
                'label' => 'Pro (Negosyo)',
                'price' => 999.0,
                'blurb' => 'Core, invoicing and supplier bills — money in and money out.',
                'features' => ['accounting', 'accounting.invoicing', 'accounting.bills'],
            ],
            // Native PH-BIR (VAT / 2550Q / SLSP), budgeting and payroll posting —
            // the things the foreign tools do not do here. Below Oojeema Premium
            // (₱2,800) and below the custom sum.
            'full' => [
                'label' => 'Full (Kumpleto)',
                'price' => 1999.0,
                'blurb' => 'Everything: invoicing, bills, BIR/VAT reporting, budgets and payroll posting.',
                'features' => [
                    'accounting',
                    'accounting.invoicing',
                    'accounting.bills',
                    'accounting.tax_vat',
                    'accounting.budgeting',
                    'accounting.payroll_posting',
                ],
            ],
            // The core engine at the base, then pick exactly what you need.
            'custom' => [
                'label' => 'Custom (Ikaw Bahala)',
                'price' => 499.0,
                'blurb' => 'The complete core engine — then add only the functions you need.',
                'features' => ['accounting'],
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
        'accounting.invoicing' => 300.0,
        'accounting.bills' => 300.0,
        'accounting.tax_vat' => 400.0,
        'accounting.budgeting' => 300.0,
        'accounting.payroll_posting' => 500.0,
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
