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
 *     deterministic turn; availability reads OFF unless the server says exactly `true`.
 *
 * Exits non-zero if any gate fails.
 */
import { readFileSync } from "node:fs";
import { matchIntentWithEntity, findIntent } from "@/modules/crm/features/copilot/engine/matcher";
import { isAiEligible } from "@/modules/crm/features/copilot/engine/ai-fallback";
import { COMPUTATION_DOCS } from "@/modules/crm/features/copilot/engine/computations";
import knowledgeData from "@/modules/crm/features/copilot/data/knowledge.json";
import intentData from "@/modules/crm/features/copilot/data/intents.json";
import quickActionData from "@/modules/crm/features/copilot/data/quick-actions.json";
import type { IntentConfig } from "@/modules/crm/features/copilot/engine/types";

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
    ["AI-off path unchanged", hook.includes("void runTurn(text, intent, text);")],
    ["quick actions never call AI directly", !quickAction.includes("runAiFallback") && !quickAction.includes("askAi")],
  ];
  report(
    "H7 hook wiring",
    checks.filter(([, ok]) => ok).length,
    checks.length,
    checks.filter(([, ok]) => !ok).map(([name]) => name),
  );
}

console.log(failed === 0 ? "\nAll AI gates passed." : `\n${failed} gate(s) FAILED.`);
process.exit(failed === 0 ? 0 : 1);
