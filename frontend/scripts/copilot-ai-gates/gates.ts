/**
 * Copilot AI gates — which free-text turns may reach the optional AI, with AI switched ON.
 *
 * Run with (from frontend/):
 *   node --experimental-strip-types --import ./scripts/copilot-gates/register.mjs ./scripts/copilot-ai-gates/gates.ts
 *
 * Drives the REAL matcher and the REAL `isAiEligible` rule the hook uses, never a copy, the same way
 * scripts/copilot-gates does. A turn that is not eligible never calls the AI endpoint: `send` in
 * use-copilot.ts calls it only behind this rule AND the server's availability boolean (gate H7 pins
 * that wiring). With AI OFF the rule is never consulted — `npm run copilot:gates` output identical to
 * HEAD is that proof; the server side of OFF is covered by CopilotSettingTest / CopilotAskTest.
 *
 * H1  DIRECT HELP REQUESTS NEVER REACH AI — "help", "commands", "what can you do" and variants.
 * H2  NO REAL INTENT REACHES AI — every keyword of every intent stays deterministic.
 * H3  EVERY KNOWLEDGE / COMPUTATION ARTICLE CAN REACH AI — each article's own question.
 * H4  A GENUINE FALLBACK CAN REACH AI.
 * H5  NATURAL-LANGUAGE CRM QUESTIONS THAT LAND ON AN ARTICLE CAN REACH AI.
 * H6  QUICK ACTIONS NEVER REACH AI — every chip binds to a real intent, so none falls back to `send`.
 * H7  WIRING — AI only behind server availability and the rule; on failure the SAME intent's
 *     deterministic turn; availability reads OFF unless the server says exactly `true`. The lead
 *     path (Phase 2 Slice 2) asks only from free text, behind availability AND the lead rule, sends
 *     only the resolved lead's id, and falls through to the rule-based lead reply.
 * H8  KNOWLEDGE PARITY — the backend's copy of the Copilot knowledge (what the model may be given)
 *     is byte-identical to the articles the rule-based Copilot ships.
 * H9  STATUS VOCABULARY PARITY — the backend's LeadVocabulary labels match lead-status.ts.
 * H10 LEAD AI RULE (PO decision D1, 2026-09-30) — only an open-ended `lead-about` question about
 *     exactly ONE resolved lead may reach the AI; exact phrasings, every other lead intent, no or
 *     several matches, and every non-lead intent never do; the lead id must be a strict positive
 *     integer.
 * H11 PAGE LEAD RULE (Phase 2 Slice 3, PO decisions D-a/D-b/D-c, 2026-10-01) — only
 *     `/zerem/leads/{id}` yields a page lead id; only an open-ended "this lead" question that names
 *     no lead of its own is a page-lead question; exact fields, named leads, every quick-action
 *     label and every intent keyword never are.
 *
 * Exits non-zero if any gate fails.
 */
import { readFileSync } from "node:fs";
import { matchIntentWithEntity, findIntent, normalize } from "@/modules/crm/features/copilot/engine/matcher";
import {
  isAiEligible,
  isLeadAiEligible,
  isPageLeadQuestion,
  leadIdForAi,
  pageLeadId,
  LEAD_AI_INTENT_ID,
  LEAD_EXACT_PHRASES,
} from "@/modules/crm/features/copilot/engine/ai-fallback";
import { COMPUTATION_DOCS } from "@/modules/crm/features/copilot/engine/computations";
import knowledgeData from "@/modules/crm/features/copilot/data/knowledge.json";
import intentData from "@/modules/crm/features/copilot/data/intents.json";
import quickActionData from "@/modules/crm/features/copilot/data/quick-actions.json";
import type { IntentConfig } from "@/modules/crm/features/copilot/engine/types";
import { LEAD_STATUS_CONFIG } from "@/modules/crm/lib/lead-status";

const CONFIG = intentData as unknown as IntentConfig;
const eligible = (q: string) => isAiEligible(matchIntentWithEntity(q, CONFIG), CONFIG);
const describe = (q: string) => {
  const m = matchIntentWithEntity(q, CONFIG);
  return `<<${q}>> -> ${m.intent.id} score ${m.score}`;
};

let failed = 0;
function report(gate: string, ok: number, total: number, detail: string[] = []) {
  const pass = ok === total;
  if (!pass) failed++;
  console.log(`${pass ? "PASS" : "FAIL"}  ${gate.padEnd(50)} ${ok}/${total}`);
  for (const d of detail.slice(0, 10)) console.log(`         ${d}`);
  if (detail.length > 10) console.log(`         … and ${detail.length - 10} more`);
}

// ── H1 ──
{
  const help = CONFIG.intents.find((i) => i.id === CONFIG.fallbackId);
  const asks = [
    ...(help?.keywords ?? []),
    "Help", "HELP!", "help?", "help me", "helpp", "hepl", "can you help",
    "What can you do?", "what else can you do", "what are your options",
  ];
  const bad: string[] = [];
  let ok = 0;
  for (const q of asks) {
    const match = matchIntentWithEntity(q, CONFIG);
    if (match.intent.id === CONFIG.fallbackId && !isAiEligible(match, CONFIG)) ok++;
    else bad.push(describe(q));
  }
  report("H1 direct help requests keep the help reply", ok, asks.length, bad);
}

// ── H2 ──
{
  const bad: string[] = [];
  let ok = 0, total = 0;
  for (const intent of CONFIG.intents) {
    for (const kw of intent.keywords) {
      total++;
      if (!eligible(kw)) ok++;
      else bad.push(`${intent.id} ${describe(kw)}`);
    }
  }
  report("H2 no real intent reaches AI", ok, total, bad);
}

// ── H3 ──
{
  const articles = [
    ...(knowledgeData as { knowledge: { id: string; question: string }[] }).knowledge,
    ...(COMPUTATION_DOCS as unknown as { id: string; question: string }[]),
  ];
  const bad: string[] = [];
  let ok = 0;
  for (const doc of articles) {
    const q = doc.question ?? "";
    if (eligible(q)) ok++;
    else bad.push(`${doc.id} ${describe(q)}`);
  }
  report("H3 every article can reach AI", ok, articles.length, bad);
}

// ── H4 ──
{
  const unmatched = ["xyz qwerty"];
  const bad = unmatched.filter((q) => !eligible(q)).map(describe);
  report("H4 genuine fallback can reach AI", unmatched.length - bad.length, unmatched.length, bad);
}

// ── H5 ──
{
  const natural = [
    "Can you analyze my lead pipeline and tell me which status has the biggest opportunity?",
    "compare my high and low priority leads",
    "what should I focus on this week",
  ];
  const bad: string[] = [];
  let ok = 0;
  for (const q of natural) {
    const m = matchIntentWithEntity(q, CONFIG);
    if (m.intent.id.startsWith("knowledge:") && isAiEligible(m, CONFIG)) ok++;
    else bad.push(describe(q));
  }
  report("H5 natural questions on an article reach AI", ok, natural.length, bad);
}

// ── H6 ──
{
  const modules = (quickActionData as { modules: Record<string, { id: string; intentId: string }[]> }).modules;
  const actions = Object.values(modules).flat();
  const bad = actions.filter((a) => !findIntent(a.intentId, CONFIG)).map((a) => `${a.id} -> ${a.intentId} (unresolved)`);
  report("H6 every quick action binds to a real intent", actions.length - bad.length, actions.length, bad);
}

// ── H7 ──
{
  const hook = readFileSync(
    new URL("../../src/modules/crm/features/copilot/hooks/use-copilot.ts", import.meta.url),
    "utf8",
  );
  const aiHook = readFileSync(
    new URL("../../src/modules/crm/features/copilot/hooks/use-copilot-ai.ts", import.meta.url),
    "utf8",
  );
  const quickAction = hook.slice(hook.indexOf("const runQuickAction"));
  const checks: [string, boolean][] = [
    ["guarded by server availability and the rule", hook.includes("aiAvailable && isAiEligible(match, INTENT_CONFIG)")],
    ["availability comes from the server hook", hook.includes("const aiAvailable = useCopilotAiAvailable();")],
    ["no build-time AI flag remains", !hook.includes("COPILOT_AI_ENABLED") && !aiHook.includes("NEXT_PUBLIC_COPILOT_AI")],
    ["availability is OFF unless the server says exactly true", aiHook.includes("return query.data === true;") && aiHook.includes("aiAvailable === true")],
    ["exactly one AI call site, given the matcher's intent", (hook.match(/runAiFallback\(text/g) ?? []).length === 1 && hook.includes("void runAiFallback(text, intent);")],
    ["AI failure runs the same intent's deterministic turn", hook.includes("if (answer === null) {\n        await runTurn(text, intent, text);")],
    ["rule-based path may offer lead AI only when the server says available", hook.includes("void runTurn(text, intent, text, aiAvailable);")],
    ["quick actions never call AI directly", !quickAction.includes("runAiFallback") && !quickAction.includes("askAi") && !quickAction.includes("aiAvailable")],
    ["lead AI only behind the lead rule and a valid resolved id", hook.includes("if (leadAi && leadId !== null && isLeadAiEligible(intent, message, matches.length)) {")],
    ["lead AI sends only the resolved lead's id", hook.includes("const leadId = only ? leadIdForAi(only.id) : null;") && hook.includes("await askAi(message, leadId);")],
    ["exactly three AI requests in the hook (fallback, lead, page lead)", (hook.match(/askAi\(/g) ?? []).length === 3],
    ["page-lead branch only behind availability and the page-lead rule", hook.includes("if (aiAvailable && isPageLeadQuestion(text)) {")],
    ["page-lead branch sends only the open page's lead id, read at send time", hook.includes("void runPageLeadTurn(text, pageLeadId(pathname));") && hook.includes("const answer = leadId === null ? null : await askAi(text, leadId);")],
    ["page-lead failure shows the fixed lead fallback, never a second AI request or article", hook.includes("const reply = answer ?? (leadId === null ? NO_PAGE_LEAD_REPLY : PAGE_LEAD_FALLBACK);") && !hook.slice(hook.indexOf("const runPageLeadTurn"), hook.indexOf("const send")).includes("runTurn") && !hook.slice(hook.indexOf("const runPageLeadTurn"), hook.indexOf("const send")).includes("runAiFallback")],
    ["page-lead branch runs before the matcher", hook.indexOf("isPageLeadQuestion(text)") < hook.indexOf("matchIntentWithEntity(text, INTENT_CONFIG)")],
    ["quick actions never use the page lead", !quickAction.includes("pageLeadId") && !quickAction.includes("runPageLeadTurn")],
    ["lead AI failure falls through to the rule-based lead reply", hook.includes("if (answer !== null) {")],
    ["lead_id is sent only as a number", aiHook.includes('typeof leadId === "number" ? { question, lead_id: leadId } : { question }')],
  ];
  report(
    "H7 hook wiring",
    checks.filter(([, ok]) => ok).length,
    checks.length,
    checks.filter(([, ok]) => !ok).map(([name]) => name),
  );
}

// ── H8 ──
{
  const pairs = ["knowledge.json", "computations.json"].map((file) => [
    file,
    readFileSync(new URL(`../../src/modules/crm/features/copilot/data/${file}`, import.meta.url)),
    readFileSync(new URL(`../../../backend/resources/copilot/knowledge/${file}`, import.meta.url)),
  ] as const);
  const bad = pairs.filter(([, front, back]) => !front.equals(back)).map(([file]) => `${file} differs`);
  report("H8 backend knowledge = frontend knowledge", pairs.length - bad.length, pairs.length, bad);
}

// ── H9 ──
{
  const php = readFileSync(
    new URL("../../../backend/app/Modules/Crm/Services/Copilot/Knowledge/LeadVocabulary.php", import.meta.url),
    "utf8",
  );
  const block = php.slice(php.indexOf("public const STATUSES"), php.indexOf("];", php.indexOf("public const STATUSES")));
  const backend = [...block.matchAll(/'([^']+)'\s*=>\s*'([^']+)'/g)].map(([, value, label]) => `${value}=${label}`);
  const frontend = LEAD_STATUS_CONFIG.map(({ value, label }) => `${value}=${label}`);
  const bad = frontend.length === backend.length && frontend.every((f, i) => f === backend[i])
    ? []
    : [`frontend ${frontend.join(", ")}`, `backend  ${backend.join(", ")}`];
  report("H9 status vocabulary = lead-status.ts", bad.length === 0 ? 1 : 0, 1, bad);
}

// ── H10 ──
{
  const LEAD = "Kopi Roasters";
  const bad: string[] = [];
  let ok = 0;
  let total = 0;
  const expect = (label: string, actual: boolean, expected: boolean) => {
    total++;
    if (actual === expected) ok++;
    else bad.push(`${label}: expected ${expected ? "eligible" : "not eligible"}`);
  };
  const exact = (q: string) => LEAD_EXACT_PHRASES.some((p) => ` ${normalize(q)} `.includes(` ${normalize(p)} `));

  // Every lead-intent phrasing, as the real matcher routes it, with one / no / several resolved leads.
  for (const intent of CONFIG.intents.filter((i) => i.response.type === "lead_lookup")) {
    for (const kw of intent.keywords) {
      const q = `${kw} ${LEAD}`;
      const m = matchIntentWithEntity(q, CONFIG);
      expect(describe(q), isLeadAiEligible(m.intent, q, 1), m.intent.id === LEAD_AI_INTENT_ID && !exact(q));
      expect(`${describe(q)} [0 leads]`, isLeadAiEligible(m.intent, q, 0), false);
      expect(`${describe(q)} [2 leads]`, isLeadAiEligible(m.intent, q, 2), false);
      if (intent.id !== LEAD_AI_INTENT_ID) expect(`${describe(q)} [other lead intent]`, isLeadAiEligible(intent, q, 1), false);
    }
  }
  // Open-ended questions reach AI; exact phrasings never do, whatever they route to.
  for (const q of [`tell me about ${LEAD}`, `kamusta si ${LEAD}`, `what about ${LEAD}`]) {
    const m = matchIntentWithEntity(q, CONFIG);
    expect(`${describe(q)} [open-ended]`, isLeadAiEligible(m.intent, q, 1), true);
  }
  const leadAbout = findIntent(LEAD_AI_INTENT_ID, CONFIG);
  for (const q of [`status of ${LEAD}`, `ano status ng ${LEAD}`, `what is the status of ${LEAD}`]) {
    expect(`<<${q}>> [exact, even on lead-about]`, leadAbout ? isLeadAiEligible(leadAbout, q, 1) : true, false);
  }
  // No non-lead intent is ever a lead-AI turn.
  for (const intent of CONFIG.intents.filter((i) => i.response.type !== "lead_lookup")) {
    for (const kw of intent.keywords) expect(`${intent.id} <<${kw}>>`, isLeadAiEligible(intent, kw, 1), false);
  }
  // The id the server receives: a strict positive integer, or no AI at all.
  const ids: [string | number, number | null][] = [
    [42, 42], ["42", 42], ["0", null], [0, null], [-1, null], ["1.5", null], ["abc", null], ["", null], ["9007199254740993", null],
  ];
  for (const [input, want] of ids) {
    total++;
    if (leadIdForAi(input) === want) ok++;
    else bad.push(`leadIdForAi(${JSON.stringify(input)}) = ${leadIdForAi(input)}, want ${want}`);
  }
  report("H10 lead AI only for one open-ended lead question", ok, total, bad);
}

// ── H11 ──
{
  const bad: string[] = [];
  let ok = 0;
  let total = 0;
  const expect = (label: string, actual: unknown, expected: unknown) => {
    total++;
    if (actual === expected) ok++;
    else bad.push(`${label}: got ${JSON.stringify(actual)}, want ${JSON.stringify(expected)}`);
  };

  // Only a lead's own detail page yields an id, and only a strict positive integer.
  const paths: [string, number | null][] = [
    ["/zerem/leads/41", 41], ["/zerem/leads/41/", 41], ["/zerem/leads/1", 1],
    ["/zerem/leads", null], ["/zerem/leads/", null], ["/zerem/leads/import", null], ["/zerem/leads/41/edit", null],
    ["/zerem/leads/0", null], ["/zerem/leads/-1", null], ["/zerem/leads/1.5", null], ["/zerem/leads/9007199254740993", null],
    ["/zerem/leads/41?tab=notes", null], ["/zerem/dashboard", null], ["/zerem/follow-ups", null], ["/leads/41", null], ["", null],
  ];
  for (const [path, want] of paths) expect(`pageLeadId(${JSON.stringify(path)})`, pageLeadId(path), want);

  // Open-ended "this lead" questions that name no lead.
  for (const q of [
    "Tell me about this lead", "tell me about this lead?", "how is this lead doing", "How's this lead doing?",
    "what about this one", "tell me about this company", "summarize this lead", "give me an overview of this lead",
    "what do you know about this client", "kamusta ang lead na ito", "kumusta itong lead", "kamusta yung lead na to",
  ]) expect(`<<${q}>> is a page-lead question`, isPageLeadQuestion(q), true);

  // Never: a named lead, an exact field (D-a), no pointer, or no open-ended ask.
  for (const q of [
    "tell me about Kopi Roasters", "tell me about Kopi Roasters, this lead", "how is Kopi doing", "what about Kopi",
    "status of this lead", "what is the status of this lead", "last activity on this lead", "when did I last contact this lead",
    "any follow up for this lead", "do I have a meeting with this lead", "next action with this lead", "ano status ng lead na ito",
    "kailan huling activity ng lead na ito", "tell me about the status of this lead", "how is this lead's follow up",
    "tell me about the last activity of this lead", "kamusta ang status ng lead na ito", "what about the next action for this one",
    "what about Kopi, this one", "tell me about my leads", "tell me about leads", "how is my pipeline doing",
    "this lead", "delete this lead", "assign this lead to me", "tell me about scoring", "help", "",
  ]) expect(`<<${q}>> is not a page-lead question`, isPageLeadQuestion(q), false);

  // Every quick-action label, and every intent keyword, keeps its own path.
  const modules = (quickActionData as { modules: Record<string, { id: string; label?: string }[]> }).modules;
  for (const action of Object.values(modules).flat()) {
    if (action.label) expect(`quick action <<${action.label}>>`, isPageLeadQuestion(action.label), false);
  }
  for (const intent of CONFIG.intents) {
    for (const kw of intent.keywords) expect(`${intent.id} <<${kw}>>`, isPageLeadQuestion(kw), false);
  }
  report("H11 page lead only for an open-ended \"this lead\" question", ok, total, bad);
}

console.log(failed === 0 ? "\nAll AI gates passed." : `\n${failed} gate(s) FAILED.`);
process.exit(failed === 0 ? 0 : 1);
