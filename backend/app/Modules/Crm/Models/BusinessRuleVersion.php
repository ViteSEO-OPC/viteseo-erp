<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use App\Modules\Crm\Enums\BusinessRuleModule;
use App\Modules\Crm\Enums\BusinessRuleState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One version of a sub-module's Business Rule configuration (Implementation Design §1.1) — the unit of
 * publish and rollback. A version carries a self-contained snapshot of its rules ({@see self::rules()}), so
 * rollback is a clean forward-copy rather than a diff replay.
 *
 * This is a thin, business-logic-free data record: casts, relationships, and scopes only. The lifecycle
 * transitions (draft → active → archived) and the "at most one active / one draft per module" invariant are
 * owned by BusinessRuleService (pessimistic row-locking; §5.4) — never by this model. `version_number` is
 * monotonic per module and never reused (§1.5). No soft delete: permanence is the `archived` state (§1.6).
 *
 * @property int $id
 * @property BusinessRuleModule $module Cast to enum; declared so static analysis applies the cast.
 * @property int $version_number Monotonic per module, never reused (§1.5).
 * @property BusinessRuleState $state Cast to enum.
 * @property string $computation_spec_version The frozen spec this version was created against (e.g. v1.0.0).
 * @property string|null $reason_for_change
 * @property int|null $created_by Null for the System-seeded baseline.
 * @property int|null $published_by
 * @property Carbon|null $published_at
 */
#[Fillable([
    // Descriptive fields the service passes through. `module`, `version_number`, and `state` are set by
    // BusinessRuleService as part of the guarded lifecycle transition — never mass-assigned from a client.
    'computation_spec_version',
    'reason_for_change',
])]
class BusinessRuleVersion extends Model
{
    use BelongsToOrg;
    protected $table = 'crm_business_rule_versions';

    protected function casts(): array
    {
        return [
            'module' => BusinessRuleModule::class,
            'version_number' => 'integer',
            'state' => BusinessRuleState::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * The rules that make up this version's snapshot, in presentation order (display_order is presentation
     * only and never affects any computed score — §1.2).
     *
     * @return HasMany<BusinessRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(BusinessRule::class, 'version_id')
            ->orderBy('category')
            ->orderBy('display_order');
    }

    /**
     * Who created this version. Null for the System-seeded baseline (Version 1).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Who published this version. Null while it is still a draft.
     *
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
