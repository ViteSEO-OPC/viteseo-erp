<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The one place that writes the audit trail (§14.3c). Call from services on
 * mutating actions. Surfaced by the Reports → Audit Trail report.
 *
 * `action` is a normalized verb key (approved | declined | assigned | updated |
 * created | submitted | enrolled | removed | login | exported | resolved |
 * scheduled | reopened); `module` is the display group (Leave, Benefits, Rules, …);
 * `description` is the human "what happened" line.
 */
class AuditService
{
    public const ACTIONS = [
        'approved', 'declined', 'assigned', 'updated', 'created', 'submitted',
        'enrolled', 'removed', 'login', 'exported', 'resolved', 'scheduled',
        // HR undoing a decision — the original entry stays, this one records the reversal.
        'reopened',
    ];

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(
        string $action,
        string $module,
        string $description,
        ?Model $auditable = null,
        ?array $before = null,
        ?array $after = null,
    ): void {
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'module' => $module,
            'description' => $description,
            'auditable_type' => $auditable ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'before' => $before,
            'after' => $after,
            'ip_address' => request()?->ip(),
        ]);
    }
}
