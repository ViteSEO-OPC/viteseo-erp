<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\BusinessRules;

use App\Modules\Crm\Enums\BusinessRuleModule;
use App\Modules\Crm\Enums\BusinessRuleState;
use App\Modules\Crm\Models\BusinessRuleVersion;
use App\Modules\Crm\Services\Scoring\ScoringConfig;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Configuration Provider — the SINGLE source that supplies {@see ScoringConfig} to the frozen scoring engine
 * (Implementation Design §6). It changes only WHERE the engine's parameters come from — file → active
 * database version — and never how they are computed. The scoring engine is completely unaware of the
 * source: it receives a fully validated {@see ScoringConfig} and nothing else, whether it originated from the
 * database, the frozen file, or a future source.
 *
 * Responsibilities (and ONLY these — no scoring logic ever migrates here):
 *   1. Active-version resolution — load the one active lead_scoring version.
 *   2. Baseline validation — delegate every check to {@see BaselineValidator} (§6.4).
 *   3. DB + file MERGE — editable per-rule POINT VALUES from the database; structural CAPS and WINDOWS stay
 *      file-backed (they are not per-rule and not admin-editable in v1) — §6.0.
 *   4. Safe fallback — on any absence/invalidity/error, return the frozen file configuration and log the
 *      condition (§6.3, §6.5). The provider NEVER throws to the engine and NEVER returns a partial config.
 *
 * Fail-safe priority (§6.3): validated database version → frozen file. Startup (§6.6) and runtime recovery
 * (§6.7) are preserved because this method is resolved lazily (per-request singleton) and every failure path
 * degrades to the known-good file baseline.
 *
 * NOTE (foundation slice): resolution is memoized per request by the container singleton binding. The
 * cross-request application cache and its post-commit invalidation (§6.2 / §5.7) are added with
 * BusinessRuleService — the only component permitted to invalidate — so no stale configuration can outlive a
 * publish. This class depends only on a read of the version model, the validator, and the file baseline —
 * never on BusinessRuleService, a controller, the queue, or HTTP (§6.9).
 */
final class ScoringConfigProvider
{
    /** The computation-spec contract the frozen engine expects (matches the seeded baseline). */
    private const EXPECTED_COMPUTATION_SPEC = 'v1.0.0';

    public function __construct(private readonly BaselineValidator $validator) {}

    public function resolve(): ScoringConfig
    {
        $fileConfig = $this->fileConfig();

        try {
            $activeVersions = BusinessRuleVersion::query()
                ->where('module', BusinessRuleModule::LEAD_SCORING->value)
                ->where('state', BusinessRuleState::ACTIVE->value)
                ->with(['rules' => fn ($query) => $query->orderBy('display_order')])
                ->get();

            /** @var list<string> $requiredKeys */
            $requiredKeys = array_keys($fileConfig['points'] ?? []);

            $result = $this->validator->validate($activeVersions, $requiredKeys, self::EXPECTED_COMPUTATION_SPEC);

            if (! $result->valid) {
                $this->logFallback($result->reason ?? 'unknown', $activeVersions->first()?->version_number);

                return ScoringConfig::fromArray($fileConfig);
            }

            /** @var BusinessRuleVersion $version */
            $version = $activeVersions->first();

            // MERGE: DB point-values (editable) over the file-backed windows/caps (structural, frozen).
            $merged = [
                'points' => $this->pointsFrom($version),
                'windows' => $fileConfig['windows'] ?? [],
                'caps' => $fileConfig['caps'] ?? [],
            ];

            $this->logLoaded($version->version_number);

            return ScoringConfig::fromArray($merged);
        } catch (Throwable $e) {
            // Database/tables unavailable (startup before seed, connection loss, missing tables). The engine
            // must never fail to score because of the business-rule configuration (§6.6 / §6.7).
            $this->logFallback('db_unavailable', null, $e);

            return ScoringConfig::fromArray($fileConfig);
        }
    }

    /**
     * Build the points map from the active version's rules. Preserves the §1.2 sign invariant: the stored
     * point_value is the absolute MAGNITUDE (never negated here — the frozen calculator applies the sign via
     * category). A DISABLED rule contributes 0.
     *
     * @return array<string, int>
     */
    private function pointsFrom(BusinessRuleVersion $version): array
    {
        $points = [];
        foreach ($version->rules as $rule) {
            $points[$rule->rule_key] = $rule->is_enabled ? $rule->point_value : 0;
        }

        return $points;
    }

    /** @return array<string, mixed> the frozen file baseline (`config/scoring.php`). */
    private function fileConfig(): array
    {
        $config = config('scoring', []);

        return is_array($config) ? $config : [];
    }

    private function logLoaded(int $versionNumber): void
    {
        Log::debug('business_rules.config_loaded', [
            'event' => 'business_rules.config_loaded',
            'source' => 'database',
            'module' => 'lead_scoring',
            'version' => $versionNumber,
        ]);
    }

    private function logFallback(string $reason, ?int $versionNumber, ?Throwable $e = null): void
    {
        Log::warning('business_rules.validation_failed', [
            'event' => 'business_rules.validation_failed',
            'reason' => $reason,
            'module' => 'lead_scoring',
            'version' => $versionNumber ?? 'none',
            'fallback' => 'file',
            'exception' => $e?->getMessage(),
        ]);
    }
}
