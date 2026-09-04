<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The catalog of SaaS-ready *systems* (products) a tenant can avail at
 * onboarding, and the bridge to each system's own plan/tier registry. Accounting
 * and Inventory price INDEPENDENTLY — one org can avail either alone, or both,
 * each on its own tier. This is the single source of truth for "which products
 * are sellable and how they price", so the onboarding controller/service stay
 * thin and a third product is a one-line addition here.
 *
 * A system's registry class must expose the standard plan-registry static API:
 * all() / keys() / exists() / get() / priceFor() and an ADDON_PRICES constant
 * (see each system's plan registry, ).
 */
class SystemRegistry
{
    /**
     * system key => [label, registry class]. The key doubles as the feature
     * namespace (e.g. "inventory" owns "inventory" + "inventory.*").
     *
     * @return array<string, array{label: string, registry: class-string}>
     */
    public static function all(): array
    {
        return [
            // ── The three ViteSEO sells ────────────────────────────────────────
            // Registering them here is what makes them appear in onboarding, in
            // billing and in entitlements at all: every one of those reads this
            // registry rather than a hardcoded list. Until 2026-09-03 only the
            // three below them were registered, which is why HRIS/CRM/PMS could
            // not be availed, priced or gated even though the modules existed.
            //
            // The feature namespace is `pms` (FeatureRegistry + launcher mapping);
            // the PERMISSION namespace stays `projects.*` -- different axes.
            'hr' => ['label' => 'HRIS', 'registry' => HrPlanRegistry::class],
            'crm' => ['label' => 'CRM', 'registry' => CrmPlanRegistry::class],
            'pms' => ['label' => 'Projects', 'registry' => ProjectsPlanRegistry::class],

        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(static::all());
    }

    public static function exists(string $system): bool
    {
        return array_key_exists($system, static::all());
    }

    public static function label(string $system): ?string
    {
        return static::all()[$system]['label'] ?? null;
    }

    /** The plan/tier registry class for a system, or null. @return class-string|null */
    public static function registry(string $system): ?string
    {
        return static::all()[$system]['registry'] ?? null;
    }

    /** Is $tier a valid tier of $system? */
    public static function tierExists(string $system, string $tier): bool
    {
        $registry = static::registry($system);

        return $registry !== null && $registry::exists($tier);
    }

    /**
     * The subset of $features that belong to a system's namespace (the module
     * key itself + its "module.*" sub-functions). Used to price each product from
     * only its own features so the two never cross-charge.
     *
     * @param  list<string>  $features
     * @return list<string>
     */
    public static function featuresOf(string $system, array $features): array
    {
        return array_values(array_filter(
            $features,
            static fn (string $f): bool => $f === $system || str_starts_with($f, $system.'.'),
        ));
    }

    /**
     * Priced à-la-carte sub-functions for a system (its "module.*" add-ons). The
     * bare module key is the tier base, never an add-on, so it's excluded.
     *
     * @return list<array{key: string, label: string, price: float}>
     */
    public static function addonsOf(string $system): array
    {
        $registry = static::registry($system);
        if ($registry === null) {
            return [];
        }

        $addons = [];
        foreach ($registry::ADDON_PRICES as $key => $price) {
            // Only this system's sub-functions. (Accounting's table also lists the
            // inventory add-ons for the legacy à-la-carte path — filter them out so
            // each product's card stays its own.)
            if (! str_starts_with($key, $system.'.')) {
                continue;
            }
            $addons[] = [
                'key' => $key,
                'label' => FeatureRegistry::label($key) ?? $key,
                'price' => (float) $price,
            ];
        }

        return $addons;
    }

    /**
     * Full onboarding catalog: each system with its core highlights, tiers
     * (+ seller blurbs) and priced add-ons, ready to render the per-system
     * pickers. `core` lists what the module's core engine includes on EVERY
     * tier — so the Custom tier never renders as "empty" (it's the whole core,
     * with extras à la carte). Both `core` and tier `blurb` are guarded with
     * method_exists / null-coalescing so a registry that hasn't adopted the
     * transparency contract yet can never break the endpoint.
     *
     * @return list<array{key: string, label: string, core: list<string>, tiers: list<array{key:string,label:string,price:float,blurb:string|null,features:list<string>}>, addons: list<array{key:string,label:string,price:float}>}>
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (static::all() as $system => $meta) {
            $registry = $meta['registry'];

            $tiers = [];
            foreach ($registry::all() as $key => $t) {
                $tiers[] = [
                    'key' => $key,
                    'label' => $t['label'],
                    'price' => (float) $t['price'],
                    'blurb' => $t['blurb'] ?? null,
                    'features' => $t['features'],
                ];
            }

            $out[] = [
                'key' => $system,
                'label' => $meta['label'],
                'core' => method_exists($registry, 'coreHighlights') ? $registry::coreHighlights() : [],
                'tiers' => $tiers,
                'addons' => static::addonsOf($system),
            ];
        }

        return $out;
    }

    /**
     * Monthly price of one system on a tier, given the full enabled feature set.
     * Only the system's own features count, so products never cross-charge.
     *
     * @param  list<string>  $features
     */
    public static function priceFor(string $system, string $tier, array $features): float
    {
        $registry = static::registry($system);
        if ($registry === null) {
            return 0.0;
        }

        return $registry::priceFor($tier, static::featuresOf($system, $features));
    }
}
