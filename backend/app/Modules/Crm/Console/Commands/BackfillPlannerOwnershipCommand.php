<?php

namespace App\Modules\Crm\Console\Commands;

use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Services\Planner\PlannerOwnershipTransfer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One-shot reconciliation of Planner ownership drift that predates the forward fix.
 *
 * BACKGROUND. Until `LeadController::assign()` learned to hand over open Planner work, reassigning a
 * lead left its events pointed at the previous BD. Those rows are still there: visible to the new
 * owner through the lead-ownership branch of PlannerEvent::visibleTo, but stamped with someone else's
 * name, and counted against BOTH BDs by every Planner-derived KPI. This command retires that debt.
 *
 * READ-ONLY BY DEFAULT — THE SAFETY PROPERTY, AND IT IS STRUCTURAL. A bare run reports and writes
 * nothing; only `--apply` transfers. The default was inverted from the usual `--dry-run` convention
 * on purpose: with `--dry-run`, the dangerous outcome is what you get by FORGETTING a flag; here it
 * is what you get by TYPING one. Nothing about a mistyped or half-remembered command can mutate data.
 *
 * OPEN WORK ONLY — completed events are never touched, on any code path. `completed_at` plus the
 * Activity trail are the record of who actually performed the work, and a reassignment must never
 * rewrite that. The report prints the protected count every run so the omission is visible rather
 * than merely claimed.
 *
 * IDEMPOTENT with no marker column and no `--force`: the selection predicate is self-extinguishing.
 * After a successful `--apply` every transferred row satisfies `assigned_to = lead.assigned_to` and
 * no longer matches, so a second run reports nothing and writes nothing.
 *
 * SCOPE CAVEAT (read {@see PlannerOwnershipTransfer::driftedOpenWork()} before running with --apply):
 * historical drift records no previous owner, so the rule compares against the CURRENT lead owner and
 * cannot distinguish stale drift from a deliberate cross-assignment. That is why every affected row is
 * printed individually — the list is meant to be approved by a human, not trusted. `--lead=` narrows
 * the run to one lead when the report surfaces something that deserves a closer look.
 */
class BackfillPlannerOwnershipCommand extends Command
{
    protected $signature = 'crm:backfill-planner-ownership
        {--apply : Perform the transfer. Without this flag the command only reports and writes nothing}
        {--lead= : Restrict to a single lead id}';

    protected $description = 'Report (or, with --apply, correct) open Planner events whose owner disagrees with their lead owner';

    public function handle(PlannerOwnershipTransfer $transfer): int
    {
        $apply = (bool) $this->option('apply');
        $leadId = $this->option('lead') !== null ? (int) $this->option('lead') : null;

        /** @var Collection<int, PlannerEvent> $drifted */
        $drifted = $transfer->driftedOpenWork($leadId)
            ->with(['lead.assignedTo', 'owner'])
            ->orderBy('lead_id')
            ->orderBy('id')
            ->get();

        $this->newLine();
        $this->info('Planner ownership backfill'.($apply
            ? ' — APPLYING (open work will be transferred)'
            : ' — READ-ONLY REPORT (pass --apply to perform the transfer)'));

        if ($drifted->isEmpty()) {
            $this->newLine();
            $this->line('No open Planner ownership drift found'.($leadId !== null ? " for lead #{$leadId}" : '').'.');
            $this->summary($leadId, 0, 0, 0);

            return self::SUCCESS;
        }

        $this->rows($drifted);
        $this->proposals($drifted, $apply);

        $transferred = 0;
        $skipped = 0;

        if ($apply) {
            foreach ($drifted as $event) {
                // Re-checked per row inside the service: a concurrent reassignment or completion
                // between the report above and this write must skip the row, never overwrite it.
                $transfer->alignToLeadOwner($event) ? $transferred++ : $skipped++;
            }
        }

        $this->summary(
            $leadId,
            $drifted->count(),
            $drifted->pluck('lead_id')->unique()->count(),
            $transferred,
            $skipped,
            $apply,
        );

        return self::SUCCESS;
    }

    /** The row-level report — every affected event, never just a count. @param Collection<int, PlannerEvent> $drifted */
    private function rows(Collection $drifted): void
    {
        $this->newLine();
        $this->table(
            ['ev', 'lead', 'company', 'planner owner', 'lead owner', 'type', 'status'],
            $drifted->map(fn (PlannerEvent $e): array => [
                $e->id,
                $e->lead_id,
                mb_strimwidth((string) ($e->lead?->business_name ?? '—'), 0, 30, '…'),
                $this->who($e->owner?->name, $e->assigned_to),
                $this->who($e->lead?->assignedTo?->name, $e->lead?->assigned_to),
                $e->event_type,
                $e->status,
            ])->all(),
        );
    }

    /** @param Collection<int, PlannerEvent> $drifted */
    private function proposals(Collection $drifted, bool $apply): void
    {
        $this->newLine();
        $this->line('<comment>'.($apply ? 'Transfers' : 'Proposed transfers').'</comment>');

        foreach ($drifted as $event) {
            $this->line(sprintf(
                '  • #%-4s %-22s → %s',
                $event->id,
                $this->who($event->owner?->name, $event->assigned_to),
                $this->who($event->lead?->assignedTo?->name, $event->lead?->assigned_to),
            ));
        }
    }

    private function summary(
        ?int $leadId,
        int $candidates,
        int $distinctLeads,
        int $transferred,
        int $skipped = 0,
        bool $apply = false,
    ): void {
        // Counted with the SAME open/completed split the transfer uses, so the protected figure is
        // evidence that completed drift was seen and declined — not merely absent from the report.
        $completedDrift = PlannerEvent::query()
            ->where('status', 'completed')
            ->whereNotNull('lead_id')
            ->whereNotNull('assigned_to')
            ->when($leadId !== null, fn (Builder $q): Builder => $q->where('lead_id', $leadId))
            ->whereHas('lead', fn (Builder $lead) => $lead
                ->whereNotNull('crm_leads.assigned_to')
                ->whereColumn('crm_leads.assigned_to', '!=', 'crm_planner_events.assigned_to'))
            ->count();

        $leadless = PlannerEvent::query()->whereNull('lead_id')->count();

        $rows = [
            [$apply ? 'Open drifted events transferred' : 'Open drifted events that WOULD transfer', $apply ? $transferred : $candidates],
            ['Distinct leads affected', $distinctLeads],
            ['Completed drifted events PROTECTED (untouched)', $completedDrift],
            ['Lead-less events (cannot drift, ignored)', $leadless],
        ];

        if ($apply && $skipped > 0) {
            $rows[] = ['Skipped (changed since the report was built)', $skipped];
        }

        $this->newLine();
        $this->table(['Result', 'Count'], $rows);

        $this->newLine();
        $this->line($apply
            ? 'Completed events were not transferred. Re-running transfers 0 rows.'
            : '<info>Nothing was written.</info> Completed events are never transferred.');
    }

    private function who(?string $name, ?int $id): string
    {
        return $id === null ? '—' : sprintf('%s #%d', $name ?? 'unknown', $id);
    }
}
