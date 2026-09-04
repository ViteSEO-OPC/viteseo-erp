<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Runtime helper for cross-module connections. Marries the static
 * ConnectionRegistry (what's possible + what each needs) with the org's
 * entitlements (what it availed) and the `org_connections` override store (what
 * it turned off).
 *
 * Rule of thumb: a connection defaults ON the moment it becomes AVAILABLE; the
 * only reason it's OFF is a stored row saying so. 'coming_soon' entries are never
 * available and never enabled.
 */
class Connections
{
    /**
     * AVAILABLE = registered, shippable (status 'available'), and every required
     * feature key is entitled to the org.
     */
    public static function available(int $orgId, string $key): bool
    {
        $entry = ConnectionRegistry::get($key);
        if ($entry === null || $entry['status'] !== 'available') {
            return false;
        }

        foreach ($entry['requires'] as $feature) {
            if (! Entitlements::has($orgId, $feature)) {
                return false;
            }
        }

        return true;
    }

    /**
     * ENABLED = available AND not turned off. No stored row => default ON; a row
     * present => honour its `enabled` flag.
     */
    public static function enabled(int $orgId, string $key): bool
    {
        if (! static::available($orgId, $key)) {
            return false;
        }

        $row = DB::table('org_connections')
            ->where('org_id', $orgId)
            ->where('connection_key', $key)
            ->first();

        return $row === null ? true : (bool) $row->enabled;
    }

    /** Upsert the org's on/off override for a connection. */
    public static function setEnabled(int $orgId, string $key, bool $enabled): void
    {
        $now = now();

        DB::table('org_connections')->updateOrInsert(
            ['org_id' => $orgId, 'connection_key' => $key],
            ['enabled' => $enabled, 'updated_at' => $now, 'created_at' => $now],
        );
    }

    /**
     * Every registry entry decorated for the given org: all registry fields plus
     * `key`, `available`, `enabled`, and `requires_labels` (feature key => human
     * label) so the UI can explain what's still needed to unlock it.
     *
     * @return list<array<string, mixed>>
     */
    public static function forOrg(int $orgId): array
    {
        $out = [];

        foreach (ConnectionRegistry::all() as $key => $entry) {
            $available = static::available($orgId, $key);

            $requiresLabels = [];
            $requiresMet = true;
            foreach ($entry['requires'] as $feature) {
                $requiresLabels[$feature] = FeatureRegistry::label($feature) ?? $feature;
                if (! Entitlements::has($orgId, $feature)) {
                    $requiresMet = false;
                }
            }

            $out[] = array_merge($entry, [
                'key' => $key,
                'available' => $available,
                'enabled' => $available ? static::enabled($orgId, $key) : false,
                'requires_labels' => $requiresLabels,
                'requires_met' => $requiresMet,
                'from_label' => SystemRegistry::label($entry['from']) ?? FeatureRegistry::label($entry['from']) ?? $entry['from'],
                'to_label' => SystemRegistry::label($entry['to']) ?? FeatureRegistry::label($entry['to']) ?? $entry['to'],
            ]);
        }

        return $out;
    }
}
