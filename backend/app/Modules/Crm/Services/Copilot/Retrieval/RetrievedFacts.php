<?php

namespace App\Modules\Crm\Services\Copilot\Retrieval;

/**
 * What one capability hands the model: allow-listed facts, each typed by unit, plus a description
 * written by code (never by the model, never from CRM content).
 *
 * The unit is what grounding keys on: a percentage may appear with `%` only if a fact supplied it as
 * a `percent`; a peso figure only if supplied as `php`.
 */
final readonly class RetrievedFacts
{
    public const COUNT = 'count';

    public const PERCENT = 'percent';

    public const PHP = 'php';

    public const POINTS = 'points';

    public const DAYS = 'days';

    public const LABEL = 'label';

    /** A calendar date, `YYYY-MM-DD` in Asia/Manila, or null when none is recorded. */
    public const DATE = 'date';

    /** Curated reference text (knowledge articles). */
    public const TEXT = 'text';

    /**
     * @param  list<array{name: string, value: mixed, unit: string}>  $facts
     * @param  string  $scope  whose figures these are — a {@see FactScope} constant, required so no
     *                         data source can reach the model without saying whose data it is
     */
    public function __construct(
        public string $source,
        public string $describes,
        public array $facts,
        public int $recordCount,
        public string $scope,
    ) {}

    public static function fact(string $name, mixed $value, string $unit): array
    {
        return ['name' => $name, 'value' => $value, 'unit' => $unit];
    }
}
