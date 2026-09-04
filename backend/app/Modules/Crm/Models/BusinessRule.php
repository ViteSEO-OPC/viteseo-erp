<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Modules\Crm\Enums\BusinessRuleCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single rule inside a Business Rule version (Implementation Design §1.2) — one row of the version's
 * self-contained snapshot. Thin data record: casts and one relationship, no business logic.
 *
 * `rule_key` is IMMUTABLE — the stable identity linking this row to the frozen scoring signal it configures
 * (e.g. `no_website`). It is set when the rule is defined and never changed; renaming is not an operation.
 *
 * **Sign invariant (§1.2, approved 2026-07-24):** `point_value` ALWAYS stores the absolute MAGNITUDE (a
 * non-negative whole number). The arithmetic sign is determined EXCLUSIVELY by {@see self::$category} — a
 * `negative` rule is subtracted by the frozen calculator; the stored value is never itself negated. This
 * matches `config/scoring.php`, so the configuration provider maps `point_value` straight into
 * `points[rule_key]` with NO sign transformation — making double-negation impossible. Displaying a negative
 * rule as `−15` is a presentation concern derived from the category (see
 * {@see BusinessRuleCategory::isSubtractive()}), never a stored sign.
 *
 * `display_order` is PRESENTATION ONLY — the additive model means order never changes any computed score.
 * No soft delete (§1.6); rules are removed only by cascade when a draft is discarded.
 *
 * **Nullability (`category`, `point_value`).** Both are lead-scoring concepts and both are NULL for the
 * `kpi` module, whose rows are catalog metadata for the code-defined M-01…M-06 formulas: a metric carries
 * no arithmetic sign and is not worth points. NULL is stored rather than 0 because 0 would assert "worth
 * zero points" and be indistinguishable from a scoring rule deliberately zeroed. Every lead_scoring row
 * still carries both, and the sign invariant above is unchanged.
 *
 * @property int $id
 * @property int $version_id
 * @property string $rule_key Immutable identity of the rule.
 * @property string $description Business-facing wording (editable).
 * @property BusinessRuleCategory|null $category Cast to enum; the sole carrier of the arithmetic sign.
 *                                               NULL only for modules with no scoring semantics (`kpi`) — see the nullability note below.
 * @property int|null $point_value Absolute magnitude — never negated (§1.2 invariant). NULL for `kpi`.
 * @property bool $is_enabled Disabled → contributes 0.
 * @property int $display_order Presentation only — never affects the score.
 */
#[Fillable([
    // Editable fields (subject to caps/validation in the service). `version_id` and `rule_key` are set at
    // definition time and never mass-assigned from a client (rule_key is immutable).
    'description',
    'category',
    'point_value',
    'is_enabled',
    'display_order',
])]
class BusinessRule extends Model
{
    use BelongsToOrg;
    protected $table = 'crm_business_rules';

    protected function casts(): array
    {
        return [
            'category' => BusinessRuleCategory::class,
            'point_value' => 'integer',
            'is_enabled' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /**
     * The version this rule belongs to (required — a rule never exists without its version).
     *
     * @return BelongsTo<BusinessRuleVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(BusinessRuleVersion::class, 'version_id');
    }
}
