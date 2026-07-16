<?php

namespace App\Modules\Crm\Services\Kpi;

/**
 * Typed, immutable view of `config/kpi.php` — the externalized BD KPI parameters (status sets and the
 * rolling activity window). The calculator depends on this object rather than the raw config array so
 * set access is type-safe (PHPStan-clean) and swappable in a test without booting the framework.
 */
final readonly class KpiConfig
{
    /**
     * @param  list<string>  $activeStatuses  M-03 ACTIVE_SET
     * @param  list<string>  $interestStatuses  M-05 numerator statuses
     * @param  list<string>  $statuses  full status enum (M-02 breakdown order)
     */
    public function __construct(
        public int $activityWindowDays,
        public array $activeStatuses,
        public array $interestStatuses,
        public string $declinedStatus,
        public array $statuses,
    ) {}

    /** @param array<string, mixed> $config the `config('kpi')` array */
    public static function fromArray(array $config): self
    {
        return new self(
            activityWindowDays: (int) ($config['activity_window_days'] ?? 30),
            activeStatuses: self::stringList($config['active_statuses'] ?? []),
            interestStatuses: self::stringList($config['interest_statuses'] ?? []),
            declinedStatus: (string) ($config['declined_status'] ?? 'declined'),
            statuses: self::stringList($config['statuses'] ?? []),
        );
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private static function stringList($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($v): string => (string) $v, $value));
    }
}
