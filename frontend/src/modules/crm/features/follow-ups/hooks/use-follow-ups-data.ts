"use client";

import { useState, useCallback, useMemo, useEffect, useRef } from "react";
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
  // The selected-item id the composer currently holds content for. See the re-hydration effect below.
  const hydratedFor = useRef<string | null>(null);

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
  // follow-up (Case A), then the injected lead context (Case B), then a follow-up as the default;
  // undefined once everything is empty. `selectedId` stays "" until the BD picks a row.
  //
  // The DEFAULT prefers a lead-linked row over `items[0]`. Purpose, Tone, and Regenerate are all fed
  // by GET /leads/{leadId}/email-templates, which cannot run without a lead — so defaulting to a
  // lead-less row opened the composer with one frozen option in each dropdown and Regenerate
  // disabled, which reads as a broken feature rather than as an item that has no lead. A manual pick
  // still wins, and a lead-less row remains selectable (it just says so — see EmailComposer).
  const selectedItem =
    items.find((i) => i.id === selectedId)
    ?? deepLinkMatch
    ?? injectedItem
    ?? items.find((i) => i.leadId)
    ?? items[0];

  const selectItem = useCallback((id: string) => {
    setSelectedId(id);
    setActiveDraftId(null); // picking a follow-up starts a fresh compose context
  }, []);

  const selectedItemId = selectedItem?.id ?? null;

  /**
   * The composer belongs to the SELECTED item — re-hydrate it whenever that identity changes.
   *
   * SAFETY, not tidiness. `draft` is one state object shared by the whole workspace, and Send/Save
   * read `subject`/`body` from it while taking `leadId`/`to` from the selected item. Without this
   * reset those two drift apart: an email addressed to lead B would carry lead A's name, company and
   * body, and nothing on screen would look wrong.
   *
   * Keyed on the selected item's ID rather than done inside `selectItem`, because `selectedItem` is
   * DERIVED — it also changes with no click at all: Mark Done clears `selectedId` and falls through
   * to `items[0]`, a deep-link match resolves late, or the current row leaves the inbox. A reset that
   * lived only in the click handler would miss every one of those.
   *
   * `hydratedFor` is what keeps `loadDraft` working: that path sets the ref to the item it is loading
   * for, so this effect sees no change and leaves the restored draft alone. It is a ref, not state,
   * because it must update synchronously with the same commit that changes the selection.
   */
  useEffect(() => {
    if (selectedItemId === hydratedFor.current) {
      return;
    }
    hydratedFor.current = selectedItemId;

    // BLANK subject/body — deliberately not INITIAL_DRAFT's placeholder copy. That constant is a
    // complete, plausible, sendable email that is IDENTICAL for every lead, so restoring it on each
    // selection both looked like auto-generation and looked like one email following the BD from lead
    // to lead. Blank is unambiguous, cannot be sent by accident (the send endpoint requires a subject
    // and body), and keeps generation strictly on the Generate button. Purpose/Tone keep their
    // defaults so the dropdowns still render and the Generate flow works unchanged.
    setDraft({ ...INITIAL_DRAFT, subject: "", body: "" });
    // Send-Later is per-email intent, never inherited: leaving it on would schedule a send for a lead
    // the BD never configured it for.
    setScheduler({ enabled: false, datetime: businessDaysAheadLocalInput(1, 9) });
    // A saved draft belongs to the item it was opened on; carrying its id over would make Send
    // transition the wrong stored row.
    setActiveDraftId(null);
  }, [selectedItemId]);

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
      if (match) {
        setSelectedId(match.id);
        // Claim the hydration for this item BEFORE the selection change commits, so the
        // re-hydration effect treats it as already handled and does not wipe the draft we are
        // about to restore. Opening a saved draft is the one selection change that legitimately
        // brings its own content.
        hydratedFor.current = match.id;
      }
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

  // The template the current Purpose + Tone resolve to. Null when the pair matches nothing — which is
  // reachable: INITIAL_DRAFT seeds a purpose/tone before any template loads, and a lead-less item
  // fetches no templates at all. Regenerate must say so rather than silently doing nothing.
  const activeTemplate = useMemo(
    () => templates.find((t) => t.purpose === draft.purpose && t.tone === draft.tone) ?? null,
    [templates, draft.purpose, draft.tone],
  );

  // Whether the BD has edited away from the rendered template. Regenerate overwrites Subject + Body,
  // so this is what decides between doing it immediately and asking first.
  const isDraftEdited = useMemo(
    () => {
      if (activeTemplate === null) {
        return false;
      }
      // A freshly selected item starts blank, and blank is not "edits worth protecting" — prompting
      // "Replace your edits?" over an empty composer would be noise on the normal first-Generate.
      if (draft.subject.trim() === "" && draft.body.trim() === "") {
        return false;
      }

      return draft.subject !== activeTemplate.subject || draft.body !== activeTemplate.body;
    },
    [activeTemplate, draft.subject, draft.body],
  );

  /**
   * The template id to send with this email, or null to send plain text.
   *
   * A designed template's blocks are only the message while the composer still says what they say.
   * The moment a BD edits the flattened text, the blocks and the message have diverged, and rendering
   * the blocks would mail the recipient something the sender never wrote — invisibly, since the HTML
   * part is the one a person reads. So the id is simply not sent, and the email goes out as the plain
   * text on screen.
   *
   * BODY ONLY, deliberately — not `isDraftEdited`, which also trips on a subject change. The subject
   * is sent as its own plain-text field and is not part of the rendered design, and the server's own
   * check compares the body alone. Matching it exactly keeps one rule, not two that can disagree.
   *
   * This is a courtesy, not the guarantee: the server recomputes the same comparison and refuses a
   * mismatched design regardless of what the browser claims.
   */
  const sendableTemplateId = useMemo(
    () => (activeTemplate !== null && draft.body === activeTemplate.body ? activeTemplate.id : null),
    [activeTemplate, draft.body],
  );

  /**
   * What the composer says about the selected template's format: "Designed · {name}" or "Plain text".
   * Null when no template matches the current Purpose + Tone. Read straight from the template — the
   * design belongs to the template (Settings → Email Templates), so there is nothing to choose here.
   */
  const templateFormat = useMemo(
    () =>
      activeTemplate === null
        ? null
        : { isDesigned: activeTemplate.isDesigned === true, designName: activeTemplate.designName ?? null },
    [activeTemplate],
  );

  /**
   * Why a designed template will NOT go out in its design, or null when it will (or is plain anyway).
   * It describes the rule that already decides the send, and adds none: edited text →
   * `sendableTemplateId` is null, the composer sends no template id, and the server's own edit check
   * would refuse the design regardless. A blank body is not "edited": nothing has been generated or
   * written yet.
   */
  const designNotice = useMemo(() => {
    if (activeTemplate?.isDesigned !== true) {
      return null;
    }
    if (draft.body.trim() !== "" && sendableTemplateId === null) {
      return "Editing the text sends this as plain text.";
    }
    return null;
  }, [activeTemplate, draft.body, sendableTemplateId]);

  /** Existing templates available for the tone the BD currently has selected. */
  const templatesForTone = useMemo(
    () => templates.filter((t) => t.tone === draft.tone),
    [templates, draft.tone],
  );

  /**
   * Regenerate — swap in a different existing EMAIL PURPOSE, holding TONE fixed.
   *
   * `GET /leads/{id}/email-templates` returns every active template already rendered for this lead
   * (templates are global assets; the backend substitutes each row's tokens for the lead), so every
   * entry is by construction a valid, existing combination. Picking among them invents nothing — no
   * new template data, no AI, no new endpoint — and the choice is handed to the same `applyTemplate`
   * the Purpose/Tone dropdowns already use.
   *
   * TONE IS NEVER RANDOMISED: the pool is pre-filtered to the BD's current tone and that same tone is
   * passed straight back to `applyTemplate`, so the Tone dropdown reads identically before and after.
   * Only Purpose moves.
   *
   * The current purpose is excluded so an explicit click visibly changes the draft; when the tone has
   * just one purpose (several do — `proposal_follow_up`, `proposal`, `meeting_reminder`, `thank_you`
   * exist only for Warm & professional) there is no alternative, and it re-applies that one, which is
   * still the useful "restore the template after my edits" behaviour.
   *
   * Reading Math.random() here is safe — this runs from a click handler, never during render. And it
   * is only ever reached from the Regenerate button: selecting another Outreach item cannot call it.
   */
  const regenerate = useCallback(() => {
    if (templatesForTone.length === 0) {
      return;
    }

    const alternatives = templatesForTone.filter((t) => t.purpose !== draft.purpose);
    const pool = alternatives.length > 0 ? alternatives : templatesForTone;
    const picked = pool[Math.floor(Math.random() * pool.length)] ?? pool[0];

    applyTemplate(picked.purpose, draft.tone);
  }, [templatesForTone, applyTemplate, draft.purpose, draft.tone]);

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
    regenerate,
    // Available whenever the CURRENT tone has at least one existing template — not only when the
    // current Purpose/Tone pair happens to match one. That also clears the dead button on a pair with
    // no template of its own (e.g. `proposal_follow_up` + Casual), whose dropdown behaviour is itself
    // unchanged; Regenerate simply moves Purpose to one that does exist for that tone.
    canRegenerate: templatesForTone.length > 0,
    isDraftEdited,
    sendableTemplateId,
    templateFormat,
    designNotice,
    isLoading,
    isError,
  };
}
