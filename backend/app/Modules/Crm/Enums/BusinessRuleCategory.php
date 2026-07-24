<?php

declare(strict_types=1);

namespace App\Modules\Crm\Enums;

/**
 * The category of a scoring rule (Implementation Design §1.0), matching the frozen scoring model. The
 * category is also the SOLE carrier of a rule's arithmetic sign (§1.2 invariant): `fit` and `engagement`
 * rules are ADDED; `negative` rules are SUBTRACTED by the frozen calculator. `BusinessRule.point_value`
 * always stores the absolute magnitude — the value is never itself negated, so the sign lives in exactly one
 * place and double-negation is impossible.
 */
enum BusinessRuleCategory: string
{
    case FIT = 'fit';
    case ENGAGEMENT = 'engagement';
    case NEGATIVE = 'negative';

    public function label(): string
    {
        return match ($this) {
            self::FIT => 'Fit',
            self::ENGAGEMENT => 'Engagement',
            self::NEGATIVE => 'Negative',
        };
    }

    /**
     * Whether this category's magnitude is subtracted from the score. This expresses the §1.2 sign
     * invariant for DISPLAY / clarity only — it is NOT applied in the configuration path (the provider
     * passes magnitudes straight through, exactly as `config/scoring.php` stores them, and the frozen
     * calculator alone performs the subtraction).
     */
    public function isSubtractive(): bool
    {
        return $this === self::NEGATIVE;
    }
}
