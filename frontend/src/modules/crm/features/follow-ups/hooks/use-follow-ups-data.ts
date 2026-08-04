"use client";

import { useState, useCallback, useMemo } from "react";
import { usePlannerEvents } from "@/modules/crm/features/calendar/hooks/use-planner-events";
import { useUpdatePlannerEvent } from "@/modules/crm/features/calendar/hooks/use-planner-mutations";
import { useLeadDetail } from "@/modules/crm/features/leads/hooks/use-lead-detail";
import { useEmailTemplates } from "@/modules/crm/features/follow-ups/hooks/use-email-templates";
import { isPendingOutreachEvent } from "@/modules/crm/features/follow-ups/lib/outreach-pending";
import { businessDaysAheadLocalInput, formatBusiness } from "@/modules/crm/lib/timezone";
import type { PlannerEvent } from "@/modules/crm/features/calendar/types/calendar-types";
import type {
  FollowUpItem,
  FollowUpStatus,
  EmailDraft,
  SchedulerState,
  AvatarColor,
} from "@/modules/crm/features/follow-ups/types/follow-ups-types";

// The Follow-ups inbox is a view over the Planner — its rows are the pending Planner events that
// are COMMUNICATION tasks Outreach fulfils: `follow-up` events and Planner `email` Email-Tasks.
// (Preserves the frozen architecture: Planner owns their schedule; Outreach lists + sends them.
// Other Planner types — call/meeting/task — stay Planner-only and never appear here.) Planner owns
// the lifecycle: Mark Done PATCHes {status:'completed'} through the existing update flow, which
// persists the completion, logs a `planner-event.updated` Activity via the observer, and lets the
// BD KPI engine's activity-volume metric react — no parallel model, endpoint, or business logic
// here. It reuses the single Planner query cache, so completing an item here also refreshes the
// Calendar and Board.
const AVATAR_COLORS: AvatarColor[] = ["teal", "blue", "orange", "purple", "gray"];

// The "actionable outreach item" definition (follow-up/email + pending) lives in one place —
// `isPendingOutreachEvent` — so the inbox list here and the sidebar Outreach badge share it exactly.

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
    eventType: event.eventType,
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

export function useFollowUpsData(leadId?: string) {
  const { data: events = [], isLoading, isError } = usePlannerEvents();
  const completeEvent = useUpdatePlannerEvent();
  // Lead reached via the ?lead= deep-link. Reuses the SAME react-query cache the Lead Detail page
  // already populated (identical queryKey), so this is a cache hit — no duplicate fetch or logic.
  // Only consulted for Case B (a lead with no pending follow-up in the inbox).
  const { lead: deepLinkLead } = useLeadDetail(leadId ?? "");

  // Only pending communication tasks (follow-ups + Email Tasks) belong in "Follow-ups Pending".
  // Filtering client-side keeps this on the one shared Planner cache (no follow-up-specific request),
  // so Mark Done's optimistic status→completed patch drops the row here the instant it is applied.
  const items = useMemo(
    () => events.filter(isPendingOutreachEvent).map(mapEventToFollowUp),
    [events],
  );

  const [selectedId, setSelectedId] = useState<string>("");
  const [draft, setDraft] = useState<EmailDraft>(INITIAL_DRAFT);
  // Default: tomorrow 09:00 business time. The previous hardcoded "07/03/2026 09:00 AM" was
  // free text in the past — unparseable by the backend's `date|after:now` rule.
  const [scheduler, setScheduler] = useState<SchedulerState>(() => ({
    enabled: false,
    datetime: businessDaysAheadLocalInput(1, 9),
  }));
  // The id of the saved draft currently loaded in the composer, or null when composing fresh.
  // Drives whether Save updates-vs-creates and whether Send transitions an existing draft.
  const [activeDraftId, setActiveDraftId] = useState<string | null>(null);

  // Case A — the deep-linked lead already has a pending follow-up in the inbox: that real event
  // becomes the default selection (below), reusing the normal composer path. Derived, not synced via
  // an effect, so a late-arriving match settles in naturally and a manual pick still overrides it.
  const deepLinkMatch = useMemo(
    () => (leadId ? items.find((i) => i.leadId === leadId) : undefined),
    [leadId, items],
  );

  // Case B — the deep-linked lead has no follow-up in the inbox: build a virtual composer context
  // straight from the lead so Outreach's existing composer opens and sends unchanged. Withheld until
  // the inbox has resolved (so a real Case-A match wins) and only when no inbox row matches.
  const injectedItem = useMemo<FollowUpItem | null>(() => {
    if (!leadId || isLoading || deepLinkMatch) return null;
    if (!deepLinkLead) return null;
    const name = deepLinkLead.contact?.trim() || deepLinkLead.business;
    return {
      id: `lead:${leadId}`,
      // A deep-linked lead context, not a real Planner event — never auto-completed (isVirtual guards it).
      eventType: "follow-up",
      leadId,
      initials: initialsFor(name),
      avatarColor: "teal",
      name,
      company: deepLinkLead.business,
      timestamp: "—",
      status: "todo",
      preview: "",
      email: deepLinkLead.email ?? "",
      isVirtual: true,
    };
  }, [leadId, isLoading, deepLinkMatch, deepLinkLead]);

  // Effective selection, most-specific first: an explicit manual pick, then the deep-linked lead's
  // follow-up (Case A), then the injected lead context (Case B), then the first follow-up as the
  // default; undefined once everything is empty. `selectedId` stays "" until the BD picks a row.
  const selectedItem =
    items.find((i) => i.id === selectedId) ?? deepLinkMatch ?? injectedItem ?? items[0];

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

  // RC3 Phase 2 — deterministic email templates for the selected lead. The Purpose + Tone dropdowns
  // draw their options from the active templates; picking a combination populates Subject + Body from
  // the matching rendered template (the BD can then edit before sending). No AI — the backend renders
  // {{token}} placeholders deterministically.
  const { templates } = useEmailTemplates(selectedItem?.leadId ?? null);
  const purposeOptions = useMemo(
    () => Array.from(new Set(templates.map((t) => t.purpose))),
    [templates],
  );
  const toneOptions = useMemo(
    () => Array.from(new Set(templates.map((t) => t.tone))),
    [templates],
  );
  const applyTemplate = useCallback(
    (purpose: string, tone: string) => {
      const match = templates.find((t) => t.purpose === purpose && t.tone === tone);
      setDraft((prev) => ({
        ...prev,
        purpose,
        tone,
        ...(match ? { subject: match.subject, body: match.body } : {}),
      }));
    },
    [templates],
  );
  const selectPurpose = useCallback((purpose: string) => applyTemplate(purpose, draft.tone), [applyTemplate, draft.tone]);
  const selectTone = useCallback((tone: string) => applyTemplate(draft.purpose, tone), [applyTemplate, draft.purpose]);

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
    purposeOptions,
    toneOptions,
    selectPurpose,
    selectTone,
    isLoading,
    isError,
  };
}
