import type { RichTextSegment } from "@/modules/crm/features/copilot/types/copilot-types";
import type {
  ResponseConfig,
  ResponseType,
  CopilotContext,
  EngineResult,
} from "@/modules/crm/features/copilot/engine/types";
import { formatNumber } from "@/modules/crm/lib/formatters";

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

function renderFollowups(config: ResponseConfig, ctx: CopilotContext): EngineResult {
  // No follow-up data in the current frontend source → generic guidance, never invented records.
  if (ctx.followUpsList.length === 0) {
    return {
      segments: toSegments(
        "I don't have live follow-up data here yet. Open **Outreach** to see your leads grouped by conversation status and pick up the ones *Awaiting Reply* or with a *Call-back Req.*",
      ),
      action: config.action,
    };
  }

  const segments: RichTextSegment[] = [
    { type: "text", text: "You have " },
    { type: "emphasis", text: `${ctx.metrics.followUps} follow-ups` },
    { type: "text", text: " due:\n" },
  ];
  for (const item of ctx.followUpsList) {
    segments.push({ type: "bold", text: item.name });
    segments.push({ type: "text", text: ` · ${item.company} — ${item.status}\n` });
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

function renderSummary(config: ResponseConfig, ctx: CopilotContext): EngineResult {
  const summary = ctx.summary;
  const segments: RichTextSegment[] = [
    { type: "text", text: "This week: " },
    { type: "bold", text: `${summary.newLeads} new leads` },
    { type: "text", text: `, ${summary.contacted} contacted, ` },
    { type: "bold", text: `${summary.followUps} follow-ups` },
    { type: "text", text: " pending. Conversion " },
    { type: "emphasis", text: summary.conversion },
    { type: "text", text: "." },
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

function renderScoreLeads(config: ResponseConfig, ctx: CopilotContext): EngineResult {
  const scoring = ctx.scoring;
  const segments: RichTextSegment[] = [
    { type: "text", text: "I re-scored " },
    { type: "emphasis", text: `${scoring.scored} new leads` },
    { type: "text", text: ` — ${scoring.movedToHigh} moved to ` },
    { type: "bold", text: "High priority" },
    { type: "text", text: "." },
  ];
  return { segments, action: config.action };
}

const HANDLERS: Record<ResponseType, Handler> = {
  text: renderText,
  template: renderText,
  navigate: renderNavigate,
  lead_priority: renderLeadPriority,
  followups: renderFollowups,
  draft_email: renderDraftEmail,
  summary: renderSummary,
  open_lead: renderOpenLead,
  score_leads: renderScoreLeads,
};
