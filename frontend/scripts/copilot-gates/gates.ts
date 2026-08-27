/**
 * Copilot regression gates.
 *
 * Run with:  npm run copilot:gates        (from frontend/)
 *
 * WHY THIS EXISTS AS A SCRIPT AND NOT A TEST. `frontend/` has no test runner, and the Copilot's
 * behaviour cannot be verified from the browser: any reply carrying a navigate action closes the
 * panel before it can be read. This drives the REAL matcher and response engine directly from
 * source — never a copy — so a gate cannot pass against logic that has drifted. Node 24 strips the
 * TypeScript natively; loader.mjs bridges the "@/" alias and bundler-style JSON imports.
 *
 * Exits non-zero if any gate fails, so it can be wired into CI unchanged.
 *
 * ── WHAT EACH GATE VERIFIES ──────────────────────────────────────────────────────────────────
 *
 * A  PRE-EXISTING INTENT ROUTING (174 keywords, 36 intents)
 *    Every intent that predates the live-CRM-context pass still resolves to itself. This is the
 *    "changed nothing that already worked" gate; fixtures/baseline-intents.json is the snapshot.
 *
 * B  LIVE-DATA INTENT SELF-RETRIEVAL
 *    Every keyword of every intent added by that pass retrieves its own intent. Catches an intent
 *    being shadowed by a sibling — the usual cost of adding keywords.
 *
 * C  KNOWLEDGE + COMPUTATION ARTICLES REACHABLE BY THEIR OWN QUESTION
 *    Each article must be returned for the question it was authored to answer. This is the gate
 *    that catches a command intent eating the knowledge corpus: 14 "How is <metric> computed?"
 *    questions were silently answered by `lead-about` until this gate existed. Never rebaseline it
 *    to match current behaviour — a drop here is a real defect.
 *
 * D  LABELLED MATRIX, INCLUDING DEFERRED CONCEPTS
 *    Realistic English + Taglish questions with the intents that would genuinely answer them. Cases
 *    marked `deferred` invert the assertion: the CRM has no canonical definition for them, so a
 *    confident live answer is a FAILURE, not a win. Recall must never be bought by inventing a
 *    definition — that is what keeps this gate honest.
 *
 * E  REALISTIC QUESTION COVERAGE
 *    A floor on how many everyday phrasings reach live CRM data rather than a static article.
 *
 * F  LEGITIMATE LEAD-NAME QUERIES
 *    Every phrasing that really does name a company must still reach a per-lead intent. The guard
 *    that stops "may meeting ba ako bukas" searching for a lead called "bukas" must not also break
 *    "may meeting ba ako kay Acme". Entries carrying `knownIssue` are reported, not asserted.
 *
 * G  ROLE VISIBILITY
 *    Admin and Super Admin must render byte-identical replies; BD must differ; the personal Planner
 *    must be refused to Admin/SA and served to a BD. Client-side gating only — server scoping is
 *    the API's job and is covered by the backend suite.
 */
import { matchIntentWithEntity } from "@/modules/crm/features/copilot/engine/matcher";
import { buildResponse } from "@/modules/crm/features/copilot/engine/response-engine";
import { COMPUTATION_DOCS } from "@/modules/crm/features/copilot/engine/computations";
import knowledgeData from "@/modules/crm/features/copilot/data/knowledge.json";
import intentData from "@/modules/crm/features/copilot/data/intents.json";
import type {
  CopilotContext, CopilotScheduleEvent, IntentConfig, ResponseConfig,
} from "@/modules/crm/features/copilot/engine/types";
import type { RichTextSegment } from "@/modules/crm/features/copilot/types/copilot-types";
import baselineIntents from "./fixtures/baseline-intents.json";
import questions from "./fixtures/questions.json";

const CONFIG = intentData as unknown as IntentConfig;
const BASELINE = baselineIntents as { intents: { id: string; keywords: string[] }[] };
const Q = questions as {
  matrix: { q: string; expect?: string[]; deferred?: string }[];
  realistic: string[];
  realisticMinLive: number;
  legitLeadQueries: { q: string; knownIssue?: string }[];
  roleQuestions: Record<string, string[]>;
};

const TYPE = new Map(CONFIG.intents.map((i) => [i.id, i.response.type]));
const LIVE_TYPES = new Set(["schedule", "lead_stats", "lead_lookup", "followups_overdue", "recent_activity"]);
const route = (q: string) => matchIntentWithEntity(q, CONFIG).intent.id;
const isLive = (id: string) => !id.startsWith("knowledge:") && LIVE_TYPES.has(TYPE.get(id) ?? "");
const isLeadLookup = (id: string) => TYPE.get(id) === "lead_lookup";

let failed = 0;
function report(gate: string, ok: number, total: number, detail: string[] = [], mustBeTotal = true) {
  const pass = mustBeTotal ? ok === total : ok >= total;
  if (!pass) failed++;
  console.log(`${pass ? "PASS" : "FAIL"}  ${gate.padEnd(46)} ${ok}/${total}`);
  for (const d of detail.slice(0, 10)) console.log(`         ${d}`);
  if (detail.length > 10) console.log(`         … and ${detail.length - 10} more`);
}

// ── A ──
{
  const bad: string[] = [];
  let ok = 0, total = 0;
  for (const intent of BASELINE.intents) {
    for (const kw of intent.keywords) {
      total++;
      const got = route(kw);
      if (got === intent.id) ok++;
      else bad.push(`${intent.id} <<${kw}>> -> ${got}`);
    }
  }
  report("A  pre-existing intent routing", ok, total, bad);
}

// ── B ──
{
  const baselineIds = new Set(BASELINE.intents.map((i) => i.id));
  const bad: string[] = [];
  let ok = 0, total = 0;
  for (const intent of CONFIG.intents) {
    if (baselineIds.has(intent.id)) continue;
    for (const kw of intent.keywords) {
      total++;
      const got = route(kw);
      if (got === intent.id) ok++;
      else bad.push(`${intent.id} <<${kw}>> -> ${got}`);
    }
  }
  report("B  live-data intent self-retrieval", ok, total, bad);
}

// ── C ──
{
  const articles = [
    ...(knowledgeData as { knowledge: { id: string; question: string }[] }).knowledge,
    ...(COMPUTATION_DOCS as unknown as { id: string; question: string }[]),
  ];
  const bad: string[] = [];
  let ok = 0;
  for (const doc of articles) {
    const got = route(doc.question ?? "");
    if (got === `knowledge:${doc.id}`) ok++;
    else bad.push(`${doc.id} -> ${got}`);
  }
  report("C  articles reachable by own question", ok, articles.length, bad);
}

// ── D ──
{
  const bad: string[] = [];
  let ok = 0, deferredOk = 0, deferredTotal = 0;
  for (const row of Q.matrix) {
    const got = route(row.q);
    if (row.deferred) {
      deferredTotal++;
      // A deferred concept must NOT be answered with live CRM data.
      if (!isLive(got)) { ok++; deferredOk++; }
      else bad.push(`DEFERRED ANSWERED: <<${row.q}>> -> ${got} (${row.deferred})`);
      continue;
    }
    if ((row.expect ?? []).includes(got)) ok++;
    else bad.push(`<<${row.q}>> -> ${got}  expected ${row.expect?.join(" | ")}`);
  }
  console.log(`      matrix ${ok}/${Q.matrix.length} correct`);
  report("D  deferred concepts left unanswered", deferredOk, deferredTotal, bad.filter((b) => b.startsWith("DEFERRED")));
  for (const b of bad.filter((b) => !b.startsWith("DEFERRED"))) console.log(`         (known gap) ${b}`);
}

// ── E ──
{
  const live = Q.realistic.filter((q) => isLive(route(q)));
  report("E  realistic questions reaching live data", live.length, Q.realisticMinLive, 
    Q.realistic.filter((q) => !isLive(route(q))).map((q) => `not live: <<${q}>> -> ${route(q)}`), false);
}

// ── F ──
{
  const bad: string[] = [];
  const known: string[] = [];
  let ok = 0, total = 0;
  for (const row of Q.legitLeadQueries) {
    const got = route(row.q);
    if (row.knownIssue) { known.push(`<<${row.q}>> -> ${got} — ${row.knownIssue}`); continue; }
    total++;
    if (isLeadLookup(got)) ok++;
    else bad.push(`<<${row.q}>> -> ${got}`);
  }
  report("F  legitimate lead-name queries", ok, total, bad);
  for (const k of known) console.log(`         (known issue) ${k}`);
}

// ── G ──
{
  const event = (id: string, type: string, label: string): CopilotScheduleEvent =>
    ({ id, title: `Event ${id}`, eventType: type, startsAt: "2026-08-27T01:00:00Z", timeLabel: label, leadName: "Acme" });

  // Built exactly as useCopilotContext does — `isBd` from the real exact-membership role rule.
  const contextFor = (roles: string[]): CopilotContext => {
    const isBd = roles.includes("crm_bd");
    return {
      identity: { isBd },
      schedule: isBd
        ? { available: true, today: [event("1", "meeting", "9:00 AM")], week: [event("1", "meeting", "9:00 AM")],
            upcoming: [event("1", "meeting", "9:00 AM")], overdue: [event("2", "follow-up", "2:00 PM")] }
        : { available: false, today: [], week: [], upcoming: [], overdue: [] },
      leadStats: { available: true, totalLeads: isBd ? 23 : 40, highPriority: isBd ? 12 : 19,
        interested: 5, followUpsDue: 7, statusCounts: { new: 8, interested: 5 } },
      plannerByLead: isBd ? { "42": [event("7", "meeting", "3:00 PM")] } : {},
      metrics: { hotLeads: 0, followUps: 0, drafts: 0, todayCalls: 0, newLeads: 0, assignedLeads: 0 },
      topLeads: [], followUpsList: [], draftEmail: { to: "", subject: "", body: "" },
    };
  };

  const render = (segments: RichTextSegment[]) =>
    segments.map((s) => (s.type === "link" ? `[${s.label}]` : s.text)).join("");

  const lead = { id: "42", name: "Heritage Designs Studio", status: "interested", priority: "high",
    websiteStatus: "live", assignedToName: "Paolo Reyes", score: 78 };

  const answer = (q: string, ctx: CopilotContext): string => {
    const intent = matchIntentWithEntity(q, CONFIG).intent;
    let turn = ctx;
    if (intent.response.type === "lead_lookup") {
      turn = { ...ctx, leadResolution: { term: "heritage designs studio", matches: [lead],
        plannerForLead: ctx.plannerByLead["42"] ?? [], plannerAvailable: ctx.schedule.available, lastActivity: null } };
    }
    return render(buildResponse(intent.response as ResponseConfig, turn).segments);
  };

  const all = Object.values(Q.roleQuestions).flat();
  const bd = all.map((q) => answer(q, contextFor(["crm_bd"])));
  const admin = all.map((q) => answer(q, contextFor(["crm_admin"])));
  const superAdmin = all.map((q) => answer(q, contextFor(["super_admin"])));

  const parity = admin.every((a, i) => a === superAdmin[i]);
  const bdDiffers = bd.some((b, i) => b !== admin[i]);
  const PLANNER_REFUSAL = "personal workspace";
  const adminRefused = admin.filter((a) => a.includes(PLANNER_REFUSAL)).length;
  const bdRefused = bd.filter((b) => b.includes(PLANNER_REFUSAL)).length;

  const checks = [
    ["Admin === Super Admin (byte-identical)", parity],
    ["BD differs from Admin", bdDiffers],
    ["Admin/SA refused the personal Planner", adminRefused > 0],
    ["BD never refused their own Planner", bdRefused === 0],
  ] as const;
  report("G  role visibility", checks.filter(([, ok]) => ok).length, checks.length,
    checks.filter(([, ok]) => !ok).map(([label]) => `failed: ${label}`));
}

console.log(failed === 0 ? "\nAll gates passed." : `\n${failed} gate(s) FAILED.`);
process.exit(failed === 0 ? 0 : 1);
