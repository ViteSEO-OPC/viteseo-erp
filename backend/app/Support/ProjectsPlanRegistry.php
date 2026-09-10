<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Subscription tiers for the standalone Projects (Sprintly) product. Prices are
 * PHP / month, PH-local-anchored per the 2026-08 pricing decisions (Julius +
 * Claude): flat per-org — massively under per-seat competitors (Asana/ClickUp/
 * Monday run ₱430–670 PER USER, so a 10-seat org pays ₱4,300–6,700/mo there).
 *
 * Anti-arbitrage: every bundle prices at-or-below its Custom + add-ons sum, so
 * à-la-carte never undercuts a bundle — Basic ₱699 ≤ ₱499 + 300 (reports) =
 * ₱799 ✓; Pro ₱899 ≤ ₱499 + 300 + 300 (reports + workload) = ₱1,099 ✓; Full
 * ₱1,099 is un-replicable by construction, because AI-assisted bulk import
 * (`pms.import`) is Full-EXCLUSIVE — deliberately absent from ADDON_PRICES, so
 * no Custom + add-ons combination ever reproduces it (the Inventory
 * serials/kits pattern).
 *
 * `features` are FeatureRegistry keys; dependencies are auto-resolved by
 * Entitlements. `blurb` is the seller-facing one-liner for the pricing page;
 * the core every tier shares lives in coreHighlights().
 */
class ProjectsPlanRegistry
{
    /**
     * @return array<string, array{label: string, price: float, blurb: string, features: list<string>}>
     */
    public static function all(): array
    {
        return [
            // Core PM + the analysis/reports pages — the everyday bundle.
            // ₱100 under custom + reports (₱799).
            'basic' => [
                'label' => 'Basic (Proyekto)',
                'price' => 699.0,
                'blurb' => '+ Cross-project activity reports',
                'features' => ['pms', 'pms.reports'],
            ],
            // Adds workload/capacity planning on top of reports. Priced ₱200
            // under the custom-sum ceiling (₱499 + 300 + 300 = ₱1,099).
            'pro' => [
                'label' => 'Pro (Planado)',
                'price' => 899.0,
                'blurb' => '+ Workload & capacity planning',
                'features' => ['pms', 'pms.reports', 'pms.workload'],
            ],
            // Everything, incl. AI-assisted bulk import (brief → LLM chat →
            // upload → preview → create). Import is Full-EXCLUSIVE (not
            // purchasable as an add-on), so Custom + every add-on (₱1,099)
            // never fully replicates this bundle even at the same price.
            'full' => [
                'label' => 'Full (Kumpleto)',
                'price' => 1099.0,
                'blurb' => '+ AI-assisted bulk import (Full exclusive)',
                'features' => ['pms', 'pms.reports', 'pms.workload', 'pms.import'],
            ],
            // Pure à-la-carte: workspaces/projects/tasks/boards at the base;
            // add exactly the functions you need.
            'custom' => [
                'label' => 'Custom (Ikaw Bahala)',
                'price' => 499.0,
                'blurb' => 'Boards, subtasks and Gantt timeline — pick add-ons as you grow',
                'features' => ['pms'],
            ],
        ];
    }

    /**
     * What the core `pms` feature — and therefore EVERY tier, bare Custom
     * included — ships with. Seller-facing pricing-page copy; SystemRegistry
     * surfaces it alongside the tiers.
     *
     * @return list<string>
     */
    public static function coreHighlights(): array
    {
        return [
            'Projects, boards & subtasks',
            'Timeline / Gantt view',
            'Task approvals & review queue',
            'Open task pool',
            'Comments & private notes',
            'Global search & archives',
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
     * `pms.import` is deliberately NOT here: it is Full-exclusive.
     *
     * @var array<string, float>
     */
    public const ADDON_PRICES = [
        'pms.reports' => 300.0,
        'pms.workload' => 300.0,
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
