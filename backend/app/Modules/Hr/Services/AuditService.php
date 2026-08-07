<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * The one place that writes the audit trail (§14.3c). Call from services on
 * mutating actions. Surfaced by the Reports → Audit Trail report.
 *
 * `action` is a normalized verb key — see `ACTIONS`, which is the single list and must stay so:
 * the audit report's action filter is built from it, and this docblock used to repeat a shorter
 * copy of it, which is how five verbs came to be written without being listed (FIN-040).
 * `module` is the display group (Leave, Benefits, Rules, …); `description` is the human
 * "what happened" line.
 */
class AuditService
{
    /**
     * The complete vocabulary. FIN-040 — it was NOT complete, and that mattered.
     *
     * Five verbs were being written and were absent from this list: `ran`, `requested`,
     * `authorized`, `cancelled` and `rejected`. The list is what the audit report's filter offers,
     * so entries written under a missing verb were invisible to anyone filtering by action — and
     * they are among the most consequential entries in the system. `ran` is a payroll run.
     * `authorized` is an employee consenting to a wage deduction under Art. 113. A trail that
     * silently omits those from its own filter is worse than one with no filter at all, because the
     * filter looks authoritative.
     *
     * Kept in one place and enforced by `assertKnownAction()` below, plus a test that scans the
     * module for every `->log('verb'` and asserts it appears here — so the next verb someone adds
     * cannot go missing the same way.
     */
    public const ACTIONS = [
        'approved', 'declined', 'assigned', 'updated', 'created', 'submitted',
        'enrolled', 'removed', 'login', 'exported', 'resolved', 'scheduled',
        // HR undoing a decision — the original entry stays, this one records the reversal.
        'reopened',
        // FIN-040 — written all along, and missing from this list until now.
        'ran',          // a payroll run executed
        'requested',    // an employee asked for something (a loan, a document)
        'authorized',   // the EMPLOYEE consented to a wage deduction (Art. 113)
        'cancelled',    // withdrawn before it took effect
        'rejected',     // refused by a reviewer
        // FIN-034 — the leave accrual engine's two automated verbs. They have no human actor, which
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

    /**
     * Keep the vocabulary honest without ever costing an operation (FIN-040).
     *
     * FAILS LOUDLY IN DEVELOPMENT, QUIETLY IN PRODUCTION, and the asymmetry is the point. An unknown
     * verb is a bug worth stopping for while someone is writing code. It is NOT worth aborting a
     * payroll run for: `log()` is called inside the same transaction as the thing it records, so
     * throwing here would roll back a legitimate disbursement over a spelling mistake in its own
     * audit line. That trade — lose the operation to protect the label — is backwards.
     *
     * So production records the entry and logs the anomaly for someone to find. A test scans the
     * module for every `->log('verb'` and asserts it is listed, which is where this is really
     * enforced; this guard catches the case that test cannot see, a verb built at runtime.
     */
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
