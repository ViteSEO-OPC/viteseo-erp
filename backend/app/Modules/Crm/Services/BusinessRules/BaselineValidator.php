<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\BusinessRules;

use App\Modules\Crm\Enums\BusinessRuleCategory;
use App\Modules\Crm\Models\BusinessRuleVersion;
use Illuminate\Support\Collection;

/**
 * The shared, stateless rule-set completeness validator (Implementation Design §5.6 / §6.4). It decides
 * whether an active database configuration may be used by the Configuration Provider, running EVERY baseline
 * check; a single failure returns an invalid result with the specific reason code (§6.12).
 *
 * Stateless and side-effect-free by design: it reads nothing, writes nothing, logs nothing (the provider
 * owns logging and fallback). It never computes a score — it only judges completeness — so no scoring logic
 * lives here.
 */
final class BaselineValidator
{
    /**
     * Run all baseline checks against the active lead_scoring version(s).
     *
     * @param  Collection<int, BusinessRuleVersion>  $activeVersions  versions already filtered to
     *         module = lead_scoring AND state = active, with their `rules` loaded.
     * @param  list<string>  $requiredKeys  every rule_key the frozen scoring model expects.
     * @param  string  $expectedSpecVersion  the computation-spec contract the engine expects (e.g. v1.0.0).
     */
    public function validate(Collection $activeVersions, array $requiredKeys, string $expectedSpecVersion): BaselineValidationResult
    {
        // Check 1 — exactly one active version (not zero, not many).
        if ($activeVersions->isEmpty()) {
            return BaselineValidationResult::invalid('no_active_version');
        }
        if ($activeVersions->count() > 1) {
            return BaselineValidationResult::invalid('multiple_active_versions');
        }

        $version = $activeVersions->first();

        // Check 5 — computation-spec version matches the engine contract.
        if ($version->computation_spec_version !== $expectedSpecVersion) {
            return BaselineValidationResult::invalid('spec_version_mismatch');
        }

        $rules = $version->rules;
        $keys = $rules->map(fn ($rule) => $rule->rule_key);

        // Check 4 — no duplicate rule keys (guarded structurally by UNIQUE(version_id, rule_key); re-checked).
        if ($keys->count() !== $keys->unique()->count()) {
            return BaselineValidationResult::invalid('duplicate_rule_key');
        }

        // Check 3 — all required rule keys present.
        $present = $keys->all();
        foreach ($requiredKeys as $required) {
            if (! in_array($required, $present, true)) {
                return BaselineValidationResult::invalid('missing_rule_key');
            }
        }

        // Check 6 — enabled-rule consistency: every rule has a valid category and an integer point value.
        // (A disabled rule is a valid state contributing 0; it does not fail validation.)
        foreach ($rules as $rule) {
            if (! $rule->category instanceof BusinessRuleCategory) {
                return BaselineValidationResult::invalid('invalid_rule_definition');
            }
            if (! is_int($rule->point_value)) {
                return BaselineValidationResult::invalid('invalid_rule_definition');
            }
        }

        // Check 2 (module) is guaranteed by the caller's query filter (module = lead_scoring).
        return BaselineValidationResult::valid();
    }
}
