<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * The one place that writes the audit trail (§14.3c). Call from services on
 * mutating actions. Surfaced by the Reports → Audit Trail report.
 */
class AuditService
{
    /** The complete vocabulary. — it was NOT complete, and that mattered. */
    public const ACTIONS = [
        'approved', 'declined', 'assigned', 'updated', 'created', 'submitted',
        'enrolled', 'removed', 'login', 'exported', 'resolved', 'scheduled',
        // HR undoing a decision — the original entry stays, this one records the reversal.
        'reopened',
        // Written all along, and missing from this list until now.
        'ran',          // a payroll run executed
        'requested',    // an employee asked for something (a loan, a document)
        'authorized',   // the EMPLOYEE consented to a wage deduction (Art. 113)
        'cancelled',    // withdrawn before it took effect
        'rejected',     // refused by a reviewer
        // The leave accrual engine's two automated verbs. They have no human actor, which
        // is exactly why they belong in the trail: an entitlement that moved on its own is the kind
        // of change an employee will query, and "the scheduler did it on 1 January" has to be
        // answerable from the log.
        'rolled_over',  // last year's unused leave carried into this one
        'expired',      // carried leave lapsed past its expiry date
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
        $this->assertKnownAction($action);

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

    /** Keep the vocabulary honest without ever costing an operation. */
    private function assertKnownAction(string $action): void
    {
        if (in_array($action, self::ACTIONS, true)) {
            return;
        }

        if (app()->environment(['local', 'testing'])) {
            throw new \InvalidArgumentException(
                "'{$action}' is not in AuditService::ACTIONS. Add it there — the audit report's "
                    . 'action filter is built from that list, so an unlisted verb writes entries '
                    . 'nobody can filter for.',
            );
        }

        Log::warning('hr.audit.unknown_action', ['action' => $action]);
    }
}
