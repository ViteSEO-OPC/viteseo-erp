"use client";

import { useMutation, useQueryClient, type QueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type {
  PlannerEvent,
  ConsultationOutcome,
  EventType,
  PlannerEventStatus,
  PlannerEventPriority,
} from "@/modules/crm/features/calendar/types/calendar-types";

/**
 * Create/Edit/Complete/Delete/Reschedule for planner events. Every mutation applies an OPTIMISTIC
 * update to the single shared list cache (`queryKeys.planner.list()`) and invalidates it on
 * settle, so Calendar, Agenda, and the future Board — all readers of that one cache entry — stay
 * synchronized automatically. There is no per-view or Board-specific state.
 */

/** Client-side draft (camelCase). Dates are UTC ISO (already converted from the business tz). */
export type PlannerEventDraft = {
  title: string;
  eventType: EventType;
  status: PlannerEventStatus;
  /** Consultation only. `null` clears it; omit to leave it untouched. */
  outcome?: ConsultationOutcome | null;
  priority: PlannerEventPriority;
  startsAt: string | null;
  endsAt: string | null;
  allDay: boolean;
  leadId: string | null;
  description: string | null;
  /** Minutes-before reminder (persistence only, no delivery yet). Omit for the backend default. */
  reminderMinutes?: number | null;
  /** Provenance — an integrated consumer sets its origin (e.g. "nearby"); omit for "manual". */
  source?: string;
};

const listKey = queryKeys.planner.list();

/**
 * Every scoped Planner History cache. A prefix, not a single key: History is cached per scope
 * ("all" / "bd:<id>"), and an Admin may have several of those entries warm at once.
 */
const historyKeyPrefix = [...queryKeys.planner.all, "history"] as const;

/**
 * Refresh both Planner representations after a write.
 *
 * The live list is invalidated as before; History is invalidated too because completing an event is
 * exactly what moves a row INTO it — without this, marking a task done would leave History showing a
 * stale set until it refetched on its own. Only the list cache is written optimistically; History is
 * read-only, so a plain invalidation is the whole story there.
 */
function invalidatePlanner(qc: QueryClient): void {
  qc.invalidateQueries({ queryKey: listKey });
  qc.invalidateQueries({ queryKey: historyKeyPrefix });
}

/** Map a camelCase draft (full or partial) to the API's snake_case payload. */
function toApiPayload(d: Partial<PlannerEventDraft>): Record<string, unknown> {
  const p: Record<string, unknown> = {};
  if (d.title !== undefined) p.title = d.title;
  if (d.eventType !== undefined) p.event_type = d.eventType;
  if (d.status !== undefined) p.status = d.status;
  if (d.outcome !== undefined) p.outcome = d.outcome;
  if (d.priority !== undefined) p.priority = d.priority;
  if (d.startsAt !== undefined) p.starts_at = d.startsAt;
  if (d.endsAt !== undefined) p.ends_at = d.endsAt;
  if (d.allDay !== undefined) p.all_day = d.allDay;
  if (d.leadId !== undefined) p.lead_id = d.leadId ? Number(d.leadId) : null;
  if (d.description !== undefined) p.description = d.description;
  if (d.reminderMinutes !== undefined) p.reminder_minutes = d.reminderMinutes;
  if (d.source !== undefined) p.source = d.source;
  return p;
}

let tempCounter = 0;

/**
 * Create a planner event. Optimistically inserts a provisional row; onSettled reconciles.
 * The single POST + shared-cache path for the whole app — Nearby "Plan Visit" reuses it verbatim,
 * passing `source: "nearby"` and its own `successMessage`, so every Planner view (Calendar, Agenda,
 * Board, Upcoming) refreshes from the one cache with no Nearby-specific scheduling logic.
 */
export function useCreatePlannerEvent(options?: { successMessage?: string }) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (draft: PlannerEventDraft) =>
      apiClient.post("/planner-events", toApiPayload(draft)).then((r) => r.data.data as PlannerEvent),
    onMutate: async (draft) => {
      await qc.cancelQueries({ queryKey: listKey });
      const prev = qc.getQueryData<PlannerEvent[]>(listKey);
      const optimistic: PlannerEvent = {
        id: `temp-${++tempCounter}`,
        title: draft.title,
        eventType: draft.eventType,
        status: draft.status,
        outcome: draft.outcome ?? null,
        priority: draft.priority,
        startsAt: draft.startsAt,
        endsAt: draft.endsAt,
        allDay: draft.allDay,
        reminderMinutes: draft.reminderMinutes ?? null,
        source: draft.source ?? "manual",
        leadId: draft.leadId,
        lead: null,
        dealId: null,
        companyId: null,
        campaignId: null,
        assignedToId: null,
        owner: null,
        description: draft.description,
        createdAt: null,
        // A provisional row is never a completion; the server's reply carries the real value.
        completedAt: null,
        updatedAt: null,
      };
      qc.setQueryData<PlannerEvent[]>(listKey, (old = []) => [...old, optimistic]);
      return { prev };
    },
    onError: (err, _draft, ctx) => {
      if (ctx?.prev) qc.setQueryData(listKey, ctx.prev);
      toast.error(getApiErrorMessage(err, "Could not create event"));
    },
    onSuccess: () => toast.success(options?.successMessage ?? "Event created"),
    onSettled: () => invalidatePlanner(qc),
  });
}

export type UpdatePlannerEventVars = {
  id: string;
  patch: Partial<PlannerEventDraft>;
  /** Suppresses the success toast for silent updates (e.g. drag reschedule). */
  silent?: boolean;
};

/** Update a planner event — powers Edit, Complete (`{status:'completed'}`), and Reschedule
 *  (`{startsAt,endsAt}`). Optimistically patches the row in place. */
export function useUpdatePlannerEvent() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, patch }: UpdatePlannerEventVars) =>
      apiClient.patch(`/planner-events/${id}`, toApiPayload(patch)).then((r) => r.data.data as PlannerEvent),
    onMutate: async ({ id, patch }) => {
      await qc.cancelQueries({ queryKey: listKey });
      const prev = qc.getQueryData<PlannerEvent[]>(listKey);
      qc.setQueryData<PlannerEvent[]>(listKey, (old = []) =>
        old.map((e) => (e.id === id ? { ...e, ...patch } : e)),
      );
      return { prev };
    },
    onError: (err, _vars, ctx) => {
      if (ctx?.prev) qc.setQueryData(listKey, ctx.prev);
      toast.error(getApiErrorMessage(err, "Could not update event"));
    },
    onSuccess: (_data, vars) => {
      if (!vars.silent) toast.success("Event updated");
    },
    onSettled: () => invalidatePlanner(qc),
  });
}

/** Soft-delete a planner event. Optimistically removes the row. */
export function useDeletePlannerEvent() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => apiClient.delete(`/planner-events/${id}`),
    onMutate: async (id) => {
      await qc.cancelQueries({ queryKey: listKey });
      const prev = qc.getQueryData<PlannerEvent[]>(listKey);
      qc.setQueryData<PlannerEvent[]>(listKey, (old = []) => old.filter((e) => e.id !== id));
      return { prev };
    },
    onError: (err, _id, ctx) => {
      if (ctx?.prev) qc.setQueryData(listKey, ctx.prev);
      toast.error(getApiErrorMessage(err, "Could not delete event"));
    },
    onSuccess: () => toast.success("Event deleted"),
    onSettled: () => invalidatePlanner(qc),
  });
}
