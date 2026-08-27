import type { RichTextSegment } from "@/modules/crm/features/copilot/types/copilot-types";
import type {
  ResponseConfig,
  ResponseType,
  CopilotContext,
  CopilotScheduleEvent,
  EngineResult,
  ScheduleScope,
} from "@/modules/crm/features/copilot/engine/types";
import { formatNumber } from "@/modules/crm/lib/formatters";
import { COMPUTATION_BY_ID } from "@/modules/crm/features/copilot/engine/computations";
import { LEAD_STATUS_CONFIG } from "@/modules/crm/lib/lead-status";

/**
 * Response engine — turns a data-driven ResponseConfig into rich-text segments (+ optional
 * action). Rendering is a registry of handlers keyed by ResponseType, so a new response kind is
 * added by dropping in one handler (Open/Closed) — no UI or matcher change. All copy is rendered
 * through the EXISTING MessageRichText component via RichTextSegment[], so there is zero new
 * presentation logic here.
 */
export function buildResponse(config: ResponseConfig, ctx: CopilotContext): EngineResult {
  const handler = HANDLERS[config.type] ?? renderText;
  return handler(config, ctx);
}

// ── Text helpers ─────────────────────────────────────────────────────────────────────────────

/** Replace {{metric}} tokens with formatted mock values; unknown tokens are left verbatim. */
function interpolate(text: string, ctx: CopilotContext): string {
  const metrics = ctx.metrics as unknown as Record<string, number>;
  return text.replace(/\{\{(\w+)\}\}/g, (whole, key: string) => {
    const value = metrics[key];
    return value === undefined ? whole : formatNumber(value);
  });
}

// Application modules that should render as clickable navigation links wherever they are mentioned.
// Keyed by the (lowercased) emphasized label authors already use in the JSON copy; the route reuses
// the app's real paths (Outreach is the /follow-ups workspace). Any emphasized token NOT in this map
// stays plain bold/emphasis (e.g. "Companies", "Discussion Notes", "Subject:").
const MODULE_ROUTES: Record<string, string> = {
  dashboard: "/zerem/dashboard",
  leads: "/zerem/leads",
  outreach: "/zerem/follow-ups",
  "follow-ups": "/zerem/follow-ups",
  "my performance": "/zerem/my-performance",
  "activity logs": "/zerem/activity-logs",
};

function moduleRoute(label: string): string | undefined {
  return MODULE_ROUTES[label.trim().toLowerCase()];
}

/**
 * Parse lightweight markup into segments so JSON authors get formatting without new segment
 * types: `**text**` → bold, `*text*` → teal emphasis, everything else → plain text. An emphasized
 * token that names an application module (see MODULE_ROUTES) is upgraded to a structured `link`
 * segment so the renderer can make it a clickable navigation link — no HTML, no markdown links.
 */
export function toSegments(text: string): RichTextSegment[] {
  return text
    .split(/(\*\*[^*]+\*\*|\*[^*]+\*)/g)
    .filter((part) => part !== "")
    .map((part): RichTextSegment => {
      const emphasized =
        part.startsWith("**") && part.endsWith("**")
          ? { inner: part.slice(2, -2), kind: "bold" as const }
          : part.startsWith("*") && part.endsWith("*")
            ? { inner: part.slice(1, -1), kind: "emphasis" as const }
            : null;

      if (!emphasized) return { type: "text", text: part };

      const route = moduleRoute(emphasized.inner);
      if (route) return { type: "link", label: emphasized.inner, route };
      return { type: emphasized.kind, text: emphasized.inner };
    });
}

// ── Handlers ──────────────────────────────────────────────────────────────────────────────────

type Handler = (config: ResponseConfig, ctx: CopilotContext) => EngineResult;

// `text` and `template` share one path — interpolation is a no-op when there are no tokens.
function renderText(config: ResponseConfig, ctx: CopilotContext): EngineResult {
  const raw = config.template ?? config.text ?? "";
  return { segments: toSegments(interpolate(raw, ctx)), action: config.action };
}

// Confirmation copy + a guaranteed navigate action (defaults to /dashboard if JSON omits one).
function renderNavigate(config: ResponseConfig, ctx: CopilotContext): EngineResult {
  const raw = config.text ?? config.template ?? "Opening…";
  return {
    segments: toSegments(interpolate(raw, ctx)),
    action: config.action ?? { type: "navigate", target: "/zerem/dashboard" },
  };
}

function renderLeadPriority(config: ResponseConfig, ctx: CopilotContext): EngineResult {
  // No lead data in the current frontend source → generic workflow guidance, never invented leads.
  if (ctx.topLeads.length === 0) {
    return {
      segments: toSegments(
        "I don't have live lead data to rank here yet. Open **Leads**, then sort by *Highest score* or filter to *High* priority to see who's worth calling first.",
      ),
      action: config.action,
    };
  }

  const segments: RichTextSegment[] = [
    { type: "text", text: "You have " },
    { type: "emphasis", text: `${ctx.metrics.hotLeads} hot leads` },
    { type: "text", text: `. Here are your top ${ctx.topLeads.length}:\n` },
  ];
  for (const lead of ctx.topLeads) {
    segments.push({ type: "bold", text: lead.name });
    segments.push({ type: "text", text: ` (${lead.score}) — ${lead.priority} — ${lead.note}\n` });
  }
  const top = ctx.topLeads[0];
  if (top) {
    segments.push({ type: "text", text: "\nStart with " });
    segments.push({ type: "emphasis", text: top.name });
    segments.push({ type: "text", text: " — highest conversion potential." });
  }
  return { segments, action: config.action };
}

function renderDraftEmail(config: ResponseConfig, ctx: CopilotContext): EngineResult {
  const draft = ctx.draftEmail;

  // No specific lead in the current frontend source → offer a generic, adaptable template
  // (boilerplate copy with no real contact, company, or record) rather than inventing a recipient.
  if (!draft.to) {
    return {
      segments: toSegments(
        "Here's a follow-up you can adapt, then send from a lead's page or **Outreach**:\n\n**Subject:** Following up on your website\n\nHi there — I wanted to follow up on improving your website to convert more visitors. Do you have 15 minutes this week for a quick look? Happy to share a couple of ideas tailored to your business.",
      ),
      action: config.action,
    };
  }

  const segments: RichTextSegment[] = [
    { type: "text", text: "Here's a draft follow-up for " },
    { type: "emphasis", text: draft.to },
    { type: "text", text: ":\n" },
    { type: "bold", text: "Subject: " },
    { type: "text", text: `${draft.subject}\n\n${draft.body}` },
  ];
  return { segments, action: config.action };
}

// Resolves the ":id" placeholder in the target against the top mock lead, emitting a concrete
// navigate action so the action engine stays agnostic to how the id was derived.
function renderOpenLead(config: ResponseConfig, ctx: CopilotContext): EngineResult {
  const lead = ctx.topLeads[0];
  const target = (config.action?.target ?? "/zerem/leads/:id").replace(":id", lead?.id ?? "");
  const segments: RichTextSegment[] = config.text
    ? toSegments(interpolate(config.text, ctx))
    : [
        { type: "text", text: "Opening " },
        { type: "emphasis", text: lead?.name ?? "the lead" },
        { type: "text", text: "…" },
      ];
  return { segments, action: { type: "navigate", target } };
}

/**
 * Explain Computation — renders one structured entry in a FIXED shape (what it measures → formula →
 * numerator/denominator → undefined-when → why → caveats → where to see it), so every "how is this
 * computed?" answer reads the same way instead of varying by author.
 *
 * Prose fields go through toSegments, so authored **bold** / *emphasis* and module links keep
 * working inside them. A `not-defined` entry deliberately renders NO formula — it states what the
 * CRM does not compute and why, which is the honest answer rather than a plausible guess.
 */
function renderComputation(config: ResponseConfig, ctx: CopilotContext): EngineResult {
  const entry = config.computationId ? COMPUTATION_BY_ID.get(config.computationId) : undefined;

  // Unknown id (a typo in JSON) degrades to the intent's own copy rather than rendering an empty
  // bubble — same fail-safe posture as the matcher's unknown-condition handling.
  if (!entry) return renderText(config, ctx);

  const segments: RichTextSegment[] = [{ type: "bold", text: entry.label }];
  const line = (label: string, value: string) => {
    segments.push({ type: "text", text: "\n" });
    segments.push({ type: "emphasis", text: `${label} ` });
    segments.push(...toSegments(value));
  };

  if (entry.confidence === "not-defined") {
    segments.push({ type: "text", text: " — not computed by the CRM.\n\n" });
    segments.push(...toSegments(entry.whyNot ?? "This value is not defined in the CRM."));
  } else {
    segments.push({ type: "text", text: ` — ${entry.measures}.\n` });
    if (entry.formula) line("Formula", entry.formula);
    if (entry.numerator) line("Numerator", entry.numerator);
    if (entry.denominator) line("Denominator", entry.denominator);
    if (entry.undefinedWhen) line("Undefined when", entry.undefinedWhen);
    if (entry.reasoning) line("Why", entry.reasoning);
    for (const caveat of entry.caveats ?? []) line("Note", caveat);
    // An entry verified in code whose written spec has since fallen behind — say so rather than
    // presenting it as settled documentation.
    if (entry.confidence === "implementation-only") {
      line("Source", "as currently implemented — the written spec predates this rule.");
    }
  }

  if (entry.surface) {
    segments.push({ type: "text", text: "\n\nSee " });
    if (entry.route) segments.push({ type: "link", label: entry.surface, route: entry.route });
    else segments.push({ type: "emphasis", text: entry.surface });
  }

  // Explaining a number never navigates the user away mid-explanation; the link above is opt-in.
  return { segments, action: { type: "none" } };
}


/**
 * Every personal schedule question, from the BD's own live Planner.
 *
 * Reads only the pre-bucketed `ctx.schedule`: the buckets are cut once in the context hook against
 * the CRM's single Manila day boundary, so nothing here parses a date or knows a timezone.
 *
 * Three distinct empty answers, because they mean different things and collapsing them would be a
 * lie: not a BD (the Planner is not theirs to see), a BD with nothing in that bucket (a real, useful
 * "you're clear"), and a bucket that is empty only because the Planner itself is empty.
 */
function renderSchedule(config: ResponseConfig, ctx: CopilotContext): EngineResult {
  const scope = config.scope ?? "today";
  const { schedule } = ctx;

  // Admin/SA: the Planner is a personal BD workspace, barred to them at the route level on purpose.
  // The Copilot must not become a side door into it — point at the management view that DOES exist.
  if (!schedule.available) {
    return {
      segments: toSegments(
        "The Planner is a Business Developer's personal workspace, so I can't show an individual's schedule here. For team follow-up load and per-BD activity, open **Reports & Performance** and scope to a BD.",
      ),
      action: { type: "navigate", target: "/zerem/reports" },
    };
  }

  const meetingsOnly =
    scope === "meetings-today" || scope === "meetings-week" || scope === "meetings-upcoming";
  const source =
    scope === "meetings-today" ? schedule.today
      : scope === "meetings-week" ? schedule.week
      : scope === "meetings-upcoming" || scope === "upcoming" ? schedule.upcoming
      : scope === "overdue" ? schedule.overdue
      : schedule.today;

  const items = meetingsOnly ? source.filter((e) => e.eventType === "meeting") : source;

  if (items.length === 0) {
    return { segments: toSegments(EMPTY_COPY[scope]), action: config.action ?? { type: "none" } };
  }

  const segments: RichTextSegment[] = [{ type: "text", text: LEAD_IN[scope](items.length) }];
  for (const item of items) {
    segments.push({ type: "bold", text: item.timeLabel });
    segments.push({ type: "text", text: ` · ${item.title}` });
    if (item.leadName) segments.push({ type: "text", text: ` — ${item.leadName}` });
    segments.push({ type: "text", text: "\n" });
  }

  return { segments, action: config.action ?? { type: "navigate", target: "/zerem/planner" } };
}

// "No meetings" and "nothing scheduled" are different facts; a BD reading these needs to know which.
const EMPTY_COPY: Record<ScheduleScope, string> = {
  "meetings-today": "No meetings on your Planner today.",
  "meetings-week": "No meetings on your Planner in the next 7 days.",
  "meetings-upcoming": "No meetings scheduled ahead on your Planner.",
  today: "Nothing scheduled on your Planner today.",
  upcoming: "Nothing scheduled ahead on your Planner.",
  overdue: "Nothing overdue — your Planner is clear.",
  "todo-today": "Nothing scheduled on your Planner today.",
};

const LEAD_IN: Record<ScheduleScope, (n: number) => string> = {
  "meetings-today": (n) => `You have ${n} meeting${n === 1 ? "" : "s"} today:\n`,
  "meetings-week": (n) => `You have ${n} meeting${n === 1 ? "" : "s"} in the next 7 days:\n`,
  "meetings-upcoming": (n) => `You have ${n} upcoming meeting${n === 1 ? "" : "s"}:\n`,
  today: (n) => `You have ${n} item${n === 1 ? "" : "s"} on your Planner today:\n`,
  upcoming: (n) => `You have ${n} item${n === 1 ? "" : "s"} coming up:\n`,
  overdue: (n) => `You have ${n} overdue item${n === 1 ? "" : "s"}:\n`,
  "todo-today": (n) => `You have ${n} item${n === 1 ? "" : "s"} on your Planner today:\n`,
};


/**
 * Portfolio counts — quoted from the dashboard KPI payload, never recomputed.
 *
 * Scope is the endpoint's: a BD is told about their own portfolio, Admin/SA about the whole CRM.
 * That is the existing lead permission model, so this is not role-gated the way the Planner is.
 */
function renderLeadStats(config: ResponseConfig, ctx: CopilotContext): EngineResult {
  const s = ctx.leadStats;
  if (!s.available) {
    return {
      segments: toSegments("I don't have your lead figures loaded yet — open the **Dashboard** and ask again."),
      action: { type: "none" },
    };
  }

  const scoped = ctx.identity.isBd ? "assigned to you" : "across the CRM";
  const stat = config.stat ?? "total";

  if (stat === "status-mix") {
    // Label AND order come from the CRM's canonical status taxonomy, so the Copilot reads a pipeline
    // back the way every other surface renders it — "Call-back Req.", not the raw `call-back-req`.
    // The payload's keys are deliberately the bare backend enum (DashboardController keeps the
    // response terminology-neutral and leaves labelling to the UI), so the mapping belongs here.
    // A key the taxonomy does not know is still reported rather than silently dropped — an expanded
    // enum must never make leads vanish from the answer.
    const known = LEAD_STATUS_CONFIG.map((cfg) => [cfg.label, s.statusCounts[cfg.value] ?? 0] as const);
    const knownKeys = new Set<string>(LEAD_STATUS_CONFIG.map((cfg) => cfg.value));
    const unknown = Object.entries(s.statusCounts).filter(([key]) => !knownKeys.has(key));
    const entries = [...known, ...unknown].filter(([, n]) => n > 0);
    if (entries.length === 0) {
      return { segments: toSegments(`No leads ${scoped} yet.`), action: config.action ?? { type: "none" } };
    }
    const segments: RichTextSegment[] = [{ type: "text", text: `Your pipeline ${scoped}:\n` }];
    for (const [status, n] of entries) {
      segments.push({ type: "bold", text: String(n) });
      segments.push({ type: "text", text: ` · ${status}\n` });
    }
    return { segments, action: config.action ?? { type: "navigate", target: "/zerem/leads" } };
  }

  const value =
    stat === "high-priority" ? s.highPriority
      : stat === "interested" ? s.interested
      : stat === "follow-ups-due" ? s.followUpsDue
      : s.totalLeads;

  const label =
    stat === "high-priority" ? `high-priority lead${value === 1 ? "" : "s"}`
      : stat === "interested" ? `interested lead${value === 1 ? "" : "s"}`
      : stat === "follow-ups-due" ? `follow-up${value === 1 ? "" : "s"} due`
      : `lead${value === 1 ? "" : "s"}`;

  return {
    segments: [
      { type: "text", text: "You have " },
      { type: "emphasis", text: `${formatNumber(value)} ${label}` },
      { type: "text", text: ` ${scoped}.` },
    ],
    action: config.action ?? { type: "navigate", target: "/zerem/leads" },
  };
}

/**
 * One named lead. The turn resolves the entity through the policy-scoped lead search BEFORE calling
 * this, so `ctx.leadResolution` already holds only what the API would return for this caller.
 *
 * "Not found" and "not yours" are answered identically ON PURPOSE: a BD asking about another BD's
 * lead must not learn that it exists. The server returns nothing; so does this.
 */
function renderLeadLookup(config: ResponseConfig, ctx: CopilotContext): EngineResult {
  const res = ctx.leadResolution;
  const ask = config.leadAsk ?? "overview";

  if (!res || !res.term) {
    return {
      segments: toSegments("Which company do you mean? Name it and I'll pull up what I have — for example *how is Acme Corp doing*."),
      action: { type: "none" },
    };
  }

  if (res.matches.length === 0) {
    return {
      segments: toSegments(`I couldn't find a lead matching **${res.term}** that you have access to. Try the exact company name, or open **Leads** to search.`),
      action: { type: "navigate", target: "/zerem/leads" },
    };
  }

  if (res.matches.length > 1) {
    const segments: RichTextSegment[] = [
      { type: "text", text: `I found ${res.matches.length} leads matching **${res.term}** — which one?\n` },
    ];
    for (const m of res.matches) {
      segments.push({ type: "bold", text: m.name });
      segments.push({ type: "text", text: ` · ${m.status}\n` });
    }
    return { segments, action: { type: "none" } };
  }

  const lead = res.matches[0];
  const events = res.plannerForLead;

  if (ask === "meeting" || ask === "follow-up" || ask === "next-action") {
    // Planner facts are BD-only — the same gate the schedule uses. Admin/SA get the management
    // pointer rather than an individual's workload.
    if (!res.plannerAvailable) {
      return {
        segments: toSegments(`I can see **${lead.name}** (${lead.status}), but Planner work is a Business Developer's personal workspace. For team follow-up load, open **Reports & Performance**.`),
        action: { type: "navigate", target: "/zerem/reports" },
      };
    }

    const wanted =
      ask === "meeting"
        ? events.filter((e: CopilotScheduleEvent) => e.eventType === "meeting")
        : events;
    if (wanted.length === 0) {
      const what = ask === "meeting" ? "meeting" : "open Planner work";
      return {
        segments: toSegments(`No ${what} scheduled with **${lead.name}** right now.`),
        action: { type: "none" },
      };
    }

    if (ask === "next-action") {
      const next = wanted[0];
      return {
        segments: [
          { type: "text", text: "Next up with " },
          { type: "bold", text: lead.name },
          { type: "text", text: `: ${next.title} — ${next.timeLabel}.` },
        ],
        action: { type: "navigate", target: "/zerem/planner" },
      };
    }

    const segments: RichTextSegment[] = [
      { type: "text", text: `${wanted.length} scheduled with ` },
      { type: "bold", text: lead.name },
      { type: "text", text: ":\n" },
    ];
    for (const e of wanted) {
      segments.push({ type: "bold", text: e.timeLabel });
      segments.push({ type: "text", text: ` · ${e.title}\n` });
    }
    return { segments, action: { type: "navigate", target: "/zerem/planner" } };
  }

  if (ask === "last-contact") {
    // Straight from the activity trail. No activity is a real, useful answer — it is stated plainly
    // rather than dressed up as a date, and it never implies the lead is inaccessible.
    if (!res.lastActivity) {
      return {
        segments: toSegments(`I don't see any recorded activity on **${lead.name}** yet.`),
        action: { type: "navigate", target: `/zerem/leads/${lead.id}` },
      };
    }
    return {
      segments: [
        { type: "text", text: "Last recorded activity on " },
        { type: "bold", text: lead.name },
        { type: "text", text: `: ${res.lastActivity.description} — ${res.lastActivity.at}.` },
      ],
      action: { type: "navigate", target: `/zerem/leads/${lead.id}` },
    };
  }

  // Overview — only fields the payload actually carries. Nothing derived, nothing inferred.
  const segments: RichTextSegment[] = [
    { type: "bold", text: lead.name },
    { type: "text", text: ` — ${lead.status}, ${lead.priority} priority` },
  ];
  if (lead.score !== null) segments.push({ type: "text", text: `, score ${lead.score}` });
  if (lead.assignedToName) segments.push({ type: "text", text: `, owned by ${lead.assignedToName}` });
  segments.push({ type: "text", text: "." });
  if (res.plannerAvailable) {
    segments.push({
      type: "text",
      text: events.length > 0
        ? ` ${events.length} open Planner item${events.length === 1 ? "" : "s"} — next is ${events[0].title} (${events[0].timeLabel}).`
        : " No open Planner work scheduled.",
    });
  }

  return { segments, action: { type: "navigate", target: `/zerem/leads/${lead.id}` } };
}

/**
 * Overdue FOLLOW-UP work — the CRM's narrower `pendingFollowUps()` set, quoted, never recomputed.
 *
 * Deliberately a different number from `schedule-overdue`, which counts the broader Planner-badge set
 * (`isActivePlannerEvent`, which includes `task` and `consultation`). Both sets are real and named;
 * `planner-active.ts` says they must not be collapsed. This handler exists so a question that says
 * "follow-up" is answered from the follow-up set instead of the badge set.
 */
function renderOverdueFollowUps(_config: ResponseConfig, ctx: CopilotContext): EngineResult {
  const overdue = ctx.overdueFollowUps;

  if (overdue === null || overdue === undefined) {
    return {
      segments: toSegments("I couldn't read your follow-up figures just now — open **Reports & Performance** for the Pending Follow-ups card."),
      action: { type: "none" },
    };
  }

  if (overdue === 0) {
    return { segments: toSegments("No overdue follow-ups — you're clear."), action: { type: "none" } };
  }

  return {
    segments: [
      { type: "text", text: "You have " },
      { type: "emphasis", text: `${formatNumber(overdue)} overdue follow-up${overdue === 1 ? "" : "s"}` },
      { type: "text", text: "." },
    ],
    action: { type: "none" },
  };
}

/**
 * "What happened recently" — the caller's own slice of the shared Activity feed, quoted as recorded.
 *
 * Scope is entirely the endpoint's (`Activity::scopeVisibleTo`), so no role gate is applied here: a
 * BD is shown their own trail and Admin/SA the CRM's, which is the existing activity permission model.
 */
function renderRecentActivity(_config: ResponseConfig, ctx: CopilotContext): EngineResult {
  const feed = ctx.recentActivity;

  if (feed === null || feed === undefined) {
    return {
      segments: toSegments("I couldn't read the activity feed just now — open **Activity Logs** for the full history."),
      action: { type: "none" },
    };
  }

  if (feed.length === 0) {
    return {
      segments: toSegments("I don't see any recent activity recorded yet."),
      action: { type: "navigate", target: "/zerem/activity-logs" },
    };
  }

  const segments: RichTextSegment[] = [
    { type: "text", text: `Most recent activity:\n` },
  ];
  for (const row of feed) {
    segments.push({ type: "bold", text: row.at });
    segments.push({ type: "text", text: ` · ${row.description}` });
    if (row.leadName) segments.push({ type: "text", text: ` — ${row.leadName}` });
    segments.push({ type: "text", text: "\n" });
  }

  return { segments, action: { type: "navigate", target: "/zerem/activity-logs" } };
}

const HANDLERS: Record<ResponseType, Handler> = {
  text: renderText,
  template: renderText,
  navigate: renderNavigate,
  lead_priority: renderLeadPriority,
  draft_email: renderDraftEmail,
  open_lead: renderOpenLead,
  computation: renderComputation,
  schedule: renderSchedule,
  lead_stats: renderLeadStats,
  lead_lookup: renderLeadLookup,
  followups_overdue: renderOverdueFollowUps,
  recent_activity: renderRecentActivity,
};
