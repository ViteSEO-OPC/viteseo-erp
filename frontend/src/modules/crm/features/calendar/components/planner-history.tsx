"use client";

import { History } from "lucide-react";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/modules/crm/components/ui/table";
import { Badge } from "@/modules/crm/components/ui/badge";
import { Skeleton } from "@/modules/crm/components/ui/skeleton";
import { formatDateLong } from "@/modules/crm/lib/formatters";
import {
  CONSULTATION_OUTCOME_META,
  EVENT_TYPE_META,
  awaitsConsultationOutcome,
} from "@/modules/crm/features/calendar/lib/event-type-meta";
import { usePlannerHistory } from "@/modules/crm/features/calendar/hooks/use-planner-history";
import type { PerformanceScope } from "@/modules/crm/features/performance/lib/performance-scope";
import type { PlannerEvent } from "@/modules/crm/features/calendar/types/calendar-types";

/**
 * Planner History — completed work, with no 7-day limit.
 *
 * Completing a task never deleted it; the live Planner simply retires a completion after 7 days so
 * the Board keeps answering "what do I owe, and what did I just finish?". This is where the rest of
 * it stays readable, with the trail that makes a completion traceable: who did it, what it was,
 * which lead, when it was created, and when it was finished.
 *
 * Read-only by design. Reopening or editing history would put the Board and the KPIs back in
 * disagreement about what "completed" means, so rows here are not editable — the live Planner
 * remains the only place work changes state.
 *
 * The owner column is rendered only when the viewer can see more than one BD's work; for a BD every
 * row is their own, so the column would be a wasted repetition of their own name.
 */
export function PlannerHistory({
  scope,
  showOwner,
}: {
  scope: PerformanceScope;
  /** Admin/Super Admin see whose completion each row was. */
  showOwner: boolean;
}) {
  const { data: events, isLoading, isError } = usePlannerHistory(scope);

  if (isLoading) {
    return (
      <div className="flex flex-col gap-2" aria-busy="true">
        {Array.from({ length: 6 }).map((_, i) => (
          <Skeleton key={i} className="h-12 w-full" />
        ))}
      </div>
    );
  }

  if (isError) {
    return (
      <EmptyState
        title="History could not be loaded"
        body="Something went wrong fetching completed work. Refresh to try again."
      />
    );
  }

  if (!events || events.length === 0) {
    return (
      <EmptyState
        title="No completed work yet"
        body={
          scope.mode === "bd"
            ? `${scope.name} has not completed any Planner work yet.`
            : "Once Planner work is completed it is kept here — nothing is ever deleted."
        }
      />
    );
  }

  return (
    <div className="overflow-x-auto rounded-xl border border-border bg-card">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Task</TableHead>
            <TableHead>Type</TableHead>
            <TableHead>Lead</TableHead>
            {showOwner && <TableHead>Completed by</TableHead>}
            <TableHead>Created</TableHead>
            <TableHead>Completed</TableHead>
            <TableHead>Status</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {events.map((event) => (
            <HistoryRow key={event.id} event={event} showOwner={showOwner} />
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

function HistoryRow({ event, showOwner }: { event: PlannerEvent; showOwner: boolean }) {
  const type = EVENT_TYPE_META[event.eventType];

  return (
    <TableRow>
      <TableCell className="font-medium text-foreground">{event.title}</TableCell>

      <TableCell>
        <span
          className="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium"
          style={{ backgroundColor: type.bg, color: type.text }}
        >
          {type.label}
        </span>
      </TableCell>

      <TableCell className="text-muted-foreground">{event.lead?.business ?? "—"}</TableCell>

      {showOwner && (
        <TableCell className="text-muted-foreground">{event.owner?.name ?? "—"}</TableCell>
      )}

      <TableCell className="whitespace-nowrap text-muted-foreground">
        {formatDateLong(event.createdAt)}
      </TableCell>

      {/* Legacy rows completed before `completed_at` existed carry a genuine null — shown as unknown
          rather than back-filled with a fabricated date. */}
      <TableCell className="whitespace-nowrap text-muted-foreground">
        {event.completedAt ? formatDateLong(event.completedAt) : "Date unknown"}
      </TableCell>

      <TableCell>
        <StatusCell event={event} />
      </TableCell>
    </TableRow>
  );
}

/**
 * Status, plus the consultation outcome where one applies. A consultation still awaiting its outcome
 * is called out rather than shown as plainly "Completed": it is the one state that is finished on the
 * Board but not yet counted by reporting, and it stays actionable on the live Planner (it never
 * expires there), so History should not imply the work is closed.
 */
function StatusCell({ event }: { event: PlannerEvent }) {
  if (event.eventType !== "consultation") {
    return <Badge variant="secondary">Completed</Badge>;
  }

  if (awaitsConsultationOutcome(event)) {
    return <Badge variant="outline">Awaiting outcome</Badge>;
  }

  const outcome = CONSULTATION_OUTCOME_META.find((o) => o.value === event.outcome);

  return <Badge variant="secondary">{outcome?.label ?? "Completed"}</Badge>;
}

function EmptyState({ title, body }: { title: string; body: string }) {
  return (
    <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-border bg-card px-6 py-16 text-center">
      <History className="h-8 w-8 text-muted-foreground" aria-hidden />
      <p className="text-sm font-semibold text-foreground">{title}</p>
      <p className="max-w-sm text-sm text-muted-foreground">{body}</p>
    </div>
  );
}
