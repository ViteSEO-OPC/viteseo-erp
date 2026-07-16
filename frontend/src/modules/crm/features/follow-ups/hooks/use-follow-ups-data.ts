"use client";

import { useState, useCallback, useMemo } from "react";
import { usePlannerEvents } from "@/modules/crm/features/calendar/hooks/use-planner-events";
import { useUpdatePlannerEvent } from "@/modules/crm/features/calendar/hooks/use-planner-mutations";
import { formatBusiness } from "@/modules/crm/lib/timezone";
import type { PlannerEvent } from "@/modules/crm/features/calendar/types/calendar-types";
import type {
  FollowUpItem,
  FollowUpStatus,
  EmailDraft,
  SchedulerState,
  AvatarColor,
} from "@/modules/crm/features/follow-ups/types/follow-ups-types";

// The Follow-ups inbox is a view over the Planner — its rows ARE follow-up PlannerEvents
// (event_type='follow-up') that are not yet completed. Planner owns the follow-up lifecycle:
// Mark Done PATCHes {status:'completed'} through the existing update flow, which persists the
// completion, logs a `planner-event.updated` Activity via the observer, and lets the BD KPI
// engine's activity-volume metric react — no parallel model, endpoint, or business logic here.
// It reuses the single Planner query cache, so completing a follow-up here also refreshes the
// Calendar and Board.
const AVATAR_COLORS: AvatarColor[] = ["teal", "blue", "orange", "purple", "gray"];

// Pending PlannerEvent statuses the inbox surfaces. `completed` is intentionally absent — a
// completed follow-up leaves the inbox (that is what Mark Done does).
const PENDING_STATUSES = new Set<FollowUpStatus>(["todo", "scheduled", "in_progress"]);

function initialsFor(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return "?";
  const letters = parts.slice(0, 2).map((p) => p[0]);
  return letters.join("").toUpperCase();
}

// A follow-up PlannerEvent → the inbox's view shape. All fields come from the real event/lead —
// nothing is fabricated. The display name falls back to the event title for a lead-less follow-up.
function mapEventToFollowUp(event: PlannerEvent, index: number): FollowUpItem {
  const name = event.lead?.contact?.trim() || event.title;
  return {
    id: event.id,
    leadId: event.leadId,
    initials: initialsFor(name),
    avatarColor: AVATAR_COLORS[index % AVATAR_COLORS.length] ?? "gray",
    name,
    company: event.lead?.business ?? "",
    // The scheduled time (business timezone), or an em dash for an undated backlog follow-up.
    timestamp: event.startsAt ? formatBusiness(event.startsAt, "MMM d, h:mm a") : "—",
    status: event.status as FollowUpStatus,
    preview: event.description?.trim() || event.title,
    // The lead's email is resolved server-side on send; the Planner payload omits it.
    email: "",
  };
}

// Seed draft (placeholder copy). The BD edits Subject/Body before sending; the send targets the
// lead linked to the currently-selected follow-up event.
const INITIAL_DRAFT: EmailDraft = {
  purpose: "Proposal Check-in",
  tone: "Warm & professional",
  subject: "Quick walkthrough of your site?",
  body: "Hi there,\n\nI'd love to walk you through a few improvements we've put together for your business.\n\nWould you have 15 minutes this week for a quick call?\n\nBest,\nVite SEO",
  aiSuggestion: "Draft your message, then send it directly to this lead. AI suggestions arrive once the Copilot module is connected.",
};

export function useFollowUpsData() {
  const { data: events = [], isLoading, isError } = usePlannerEvents();
  const completeEvent = useUpdatePlannerEvent();

  // Only pending follow-up events belong in "Follow-ups Pending". Filtering client-side keeps this
  // on the one shared Planner cache (no follow-up-specific request), so Mark Done's optimistic
  // status→completed patch drops the row here the instant it is applied.
  const items = useMemo(
    () =>
      events
        .filter((e) => e.eventType === "follow-up" && PENDING_STATUSES.has(e.status as FollowUpStatus))
        .map(mapEventToFollowUp),
    [events],
  );

  const [selectedId, setSelectedId] = useState<string>("");
  const [draft, setDraft] = useState<EmailDraft>(INITIAL_DRAFT);
  const [scheduler, setScheduler] = useState<SchedulerState>({ enabled: false, datetime: "07/03/2026 09:00 AM" });
  // The id of the saved draft currently loaded in the composer, or null when composing fresh.
  // Drives whether Save updates-vs-creates and whether Send transitions an existing draft.
  const [activeDraftId, setActiveDraftId] = useState<string | null>(null);

  // Default to the first follow-up until the BD picks one; undefined once the inbox is empty.
  const selectedItem = items.find((i) => i.id === selectedId) ?? items[0];

  const selectItem = useCallback((id: string) => {
    setSelectedId(id);
    setActiveDraftId(null); // picking a follow-up starts a fresh compose context
  }, []);

  // Mark Done: complete the follow-up through Planner's existing update flow. `completed` fails the
  // pending filter above, so the optimistic patch removes the row immediately; selection falls back
  // to the next follow-up (selectedItem recomputes). No manual score/KPI mutation — those engines
  // react to the logged Activity on their own.
  const markDone = useCallback(
    (id: string) => {
      completeEvent.mutate({ id, patch: { status: "completed" } });
      if (id === selectedId) setSelectedId("");
    },
    [completeEvent, selectedId],
  );

  // Load a saved draft back into the composer for editing/sending.
  const loadDraft = useCallback((d: { id: string; leadId: string | null; subject: string; body: string }) => {
    if (d.leadId) {
      // Select the pending follow-up for this lead, if one is in the inbox.
      const match = items.find((i) => i.leadId === d.leadId);
      if (match) setSelectedId(match.id);
    }
    setDraft((prev) => ({ ...prev, subject: d.subject, body: d.body }));
    setActiveDraftId(d.id);
  }, [items]);

  const updateDraft = useCallback((updates: Partial<EmailDraft>) => {
    setDraft((prev) => ({ ...prev, ...updates }));
  }, []);

  const toggleSchedule = useCallback(() => {
    setScheduler((prev) => ({ ...prev, enabled: !prev.enabled }));
  }, []);

  const setScheduleDatetime = useCallback((datetime: string) => {
    setScheduler((prev) => ({ ...prev, datetime }));
  }, []);

  return {
    items,
    selectedId: selectedItem?.id ?? "",
    selectedItem,
    draft,
    scheduler,
    activeDraftId,
    setActiveDraftId,
    loadDraft,
    selectItem,
    markDone,
    markingDone: completeEvent.isPending,
    updateDraft,
    toggleSchedule,
    setScheduleDatetime,
    isLoading,
    isError,
  };
}
