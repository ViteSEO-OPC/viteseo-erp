<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Crm\Enums\BusinessRuleAuditAction;
use App\Modules\Crm\Enums\BusinessRuleModule;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An immutable, append-only Business Rule audit entry (Implementation Design §1.3). Follows the existing
 * `crm_activities` pattern exactly: `created_at` only — NO `updated_at` (see {@see self::UPDATED_AT}), no
 * soft delete. Once written, an entry is a permanent part of history; the model provides no path to mutate
 * it (writes go through BusinessRuleService inside the same transaction as the change they record — §5.5).
 *
 * `version_number` is a LOOSE integer reference, NOT a foreign key — mirroring `crm_activities.subject_id` —
 * so the trail survives even if a draft it recorded is later hard-discarded (§1.3).
 *
 * @property int $id
 * @property BusinessRuleModule $module Cast to enum.
 * @property BusinessRuleAuditAction $action Cast to enum.
 * @property string|null $rule_key Null for version-level actions (publish, rollback, discard_draft, seed).
 * @property string|null $previous_value
 * @property string|null $new_value
 * @property int $version_number Loose reference to the version the action affected (not a FK).
 * @property string $computation_spec_version
 * @property string|null $reason_for_change
 * @property int|null $user_id Null for System actions.
 * @property Carbon $created_at Append-only timestamp.
 */
#[Fillable([
    'module',
    'action',
    'rule_key',
    'previous_value',
    'new_value',
    'version_number',
    'computation_spec_version',
    'reason_for_change',
    'user_id',
])]
class BusinessRuleAudit extends Model
{
    protected $table = 'crm_business_rule_audit';

    /** Append-only: no updated_at column (matches crm_activities; §1.3 / §1.6). */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'module' => BusinessRuleModule::class,
            'action' => BusinessRuleAuditAction::class,
            'version_number' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The user who performed the action. Null for System actions (e.g. the seed baseline).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
