<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Subscription tiers for the standalone CRM (Zerem) product. Prices are PHP /
 * month, PH-local-anchored per the 2026-08 pricing decisions (Julius + Claude):
 * flat per-org against per-seat competitors (Zoho CRM/Pipedrive/HubSpot Starter
 * run ₱800–1,200 PER USER, so a 10-seat org pays ₱8,000+ there). `features` are
 * FeatureRegistry keys; dependencies auto-resolved by Entitlements.
 *
 * Proposal B ladder (approved 2026-08-19) — four tiers, each bundle at-or-below
 * every à-la-carte or upgrade path that could replicate it (no arbitrage):
 *
 *   custom ₱499  — crm
 *   basic  ₱699  — + crm.performance          (≤ 499 + 300 = ₱799 ✓)
 *   pro    ₱1,099 — + crm.outreach            (≤ 499 + 300 + 500 = ₱1,299 ✓;
 *                                              ≤ basic + 500 = ₱1,199 ✓)
 *   full   ₱1,399 — + crm.nearby + crm.automation
 *                                             (≤ 499 + 300 + 500 + 300 = ₱1,599 ✓;
 *                                              ≤ pro + 300 = ₱1,399 ✓ equal, allowed;
 *                                              ≤ basic + 300 + 500 = ₱1,499 ✓)
 *
 * `crm.automation` is deliberately ABSENT from ADDON_PRICES — it is
 * Full-exclusive and cannot be replicated à la carte at any price (the same
 * pattern as pms.import), which is what makes the Full bundle un-arbitrageable
 * by construction.
 */
class CrmPlanRegistry
{
    /**
     * What the CORE CRM engine includes on EVERY tier (custom included).
     * Rendered on the onboarding cards so "Custom" never looks empty.
     *
     * Honest post-restructure list: performance dashboards/KPIs/BD ranking left
     * the core for `crm.performance`, so the core line covers the Activity log
     * and the basic lead insights (KPI cards, status/priority summaries) that
     * genuinely stay on every tier.
     *
     * @return list<string>
     */
    public static function coreHighlights(): array
    {
        return [
            'Lead capture, import & archiving',
            'Sales pipeline with statuses & priorities',
            'Activity planner & follow-up work queue',
            'Calls, notes & attachments per lead',
            'Activity log & lead insights',
            'Team management & lead assignment',
        ];
    }

    /**
     * @return array<string, array{label: string, price: float, blurb: string, features: list<string>}>
     */
    public static function all(): array
    {
        return [
            // Core + the performance analytics pack. ₱100 below custom + the
            // performance add-on (₱799).
            'basic' => [
                'label' => 'Basic (Performance)',
                'price' => 699.0,
                'blurb' => '+ Performance dashboards, KPIs & BD ranking',
                'features' => ['crm', 'crm.performance'],
            ],
            // Basic + email outreach (scheduled sends, reply threading, the
            // unmatched inbox). Below both replication paths (₱1,299 / ₱1,199).
            'pro' => [
                'label' => 'Pro (Outreach)',
                'price' => 1099.0,
                'blurb' => '+ Email outreach with reply threading & unmatched inbox',
                'features' => ['crm', 'crm.performance', 'crm.outreach'],
            ],
            // Everything: Pro + the Nearby Clients discovery map + the n8n
            // outreach automation pack (Full-exclusive — not sold à la carte).
            'full' => [
                'label' => 'Full (Kumpleto)',
                'price' => 1399.0,
                'blurb' => '+ Prospect discovery map & outreach automation (Full exclusive)',
                'features' => ['crm', 'crm.performance', 'crm.outreach', 'crm.nearby', 'crm.automation'],
            ],
            // Pure à-la-carte base: the complete core CRM engine.
            'custom' => [
                'label' => 'Custom (Ikaw Bahala)',
                'price' => 499.0,
                'blurb' => 'The complete core — leads, pipeline, planner, inbox — pick add-ons as you need',
                'features' => ['crm'],
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
     * `crm.automation` is intentionally not listed: Full-exclusive (see the
     * class docblock).
     *
     * @var array<string, float>
     */
    public const ADDON_PRICES = [
        'crm.nearby' => 300.0,
        'crm.performance' => 300.0,
        'crm.outreach' => 500.0,
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
