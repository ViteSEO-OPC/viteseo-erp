<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\BusinessRules;

/**
 * The outcome of a baseline validation (Implementation Design §6.4). Immutable value object: either the
 * database configuration is verifiably complete and correct (`valid`), or it is not and carries the specific
 * `reason` code (one of the §6.12 structured-logging codes) that sent the provider to the file fallback.
 *
 * Validation is ALL-OR-NOTHING — a single failed check yields an invalid result, and the provider serves the
 * frozen file for the entire parameter set. There is no partial-configuration outcome.
 */
final readonly class BaselineValidationResult
{
    private function __construct(
        public bool $valid,
        public ?string $reason,
    ) {}

    public static function valid(): self
    {
        return new self(true, null);
    }

    /** @param string $reason one of the §6.12 reason codes (e.g. missing_rule_key, spec_version_mismatch). */
    public static function invalid(string $reason): self
    {
        return new self(false, $reason);
    }
}
