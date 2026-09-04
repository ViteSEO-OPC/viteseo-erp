<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Which functions an org has availed. Presence of a row in `org_features` means
 * the org has that function. Enabling a function auto-includes its dependencies
 * (transitively) via the FeatureRegistry, so you can never enable "Payroll
 * posting" without also getting "Accounting" and "HR".
 */
class Entitlements
{
    /** @return list<string> The feature keys enabled for the org. */
    public static function keysFor(int $orgId): array
    {
        return DB::table('org_features')
            ->where('org_id', $orgId)
            ->pluck('feature_key')
            ->all();
    }

    public static function has(int $orgId, string $key): bool
    {
        return DB::table('org_features')
            ->where('org_id', $orgId)
            ->where('feature_key', $key)
            ->exists();
    }

    /**
     * Expand a set of feature keys to include all transitive dependencies.
     * Unknown keys are ignored.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function resolve(array $keys): array
    {
        $resolved = [];

        $visit = function (string $key) use (&$visit, &$resolved): void {
            if (isset($resolved[$key]) || ! FeatureRegistry::exists($key)) {
                return;
            }

            $resolved[$key] = true;

            foreach (FeatureRegistry::requires($key) as $dependency) {
                $visit($dependency);
            }
        };

        foreach ($keys as $key) {
            $visit($key);
        }

        return array_keys($resolved);
    }

    /**
     * Enable functions for an org (plus their dependencies). Idempotent.
     *
     * @param  list<string>  $keys
     * @return list<string>  The full resolved set that is now enabled.
     */
    public static function enable(int $orgId, array $keys): array
    {
        $resolved = static::resolve($keys);
        $now = now();

        foreach ($resolved as $key) {
            DB::table('org_features')->insertOrIgnore([
                'org_id' => $orgId,
                'feature_key' => $key,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $resolved;
    }

    /**
     * Make the org's enabled set EXACTLY the resolved closure of $keys — enable
     * the picks (plus dependencies) and disable everything else in the catalog.
     * Used by the admin "set features" endpoint.
     *
     * @param  list<string>  $keys
     * @return list<string>  The resulting enabled set.
     */
    public static function sync(int $orgId, array $keys): array
    {
        $resolved = static::resolve($keys);

        static::enable($orgId, $resolved);
        static::disable($orgId, array_values(array_diff(FeatureRegistry::keys(), $resolved)));

        return $resolved;
    }

    /**
     * Disable functions for an org. Does NOT cascade to dependents — the caller
     * decides whether removing a dependency should also remove what needs it.
     *
     * @param  list<string>  $keys
     */
    public static function disable(int $orgId, array $keys): void
    {
        DB::table('org_features')
            ->where('org_id', $orgId)
            ->whereIn('feature_key', $keys)
            ->delete();
    }
}
