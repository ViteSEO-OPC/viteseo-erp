/**
 * Copilot AI-fallback gates — which free-text turns may reach the optional AI fallback.
 *
 * Run with (from frontend/):
 *   node --experimental-strip-types --import ./scripts/copilot-gates/register.mjs ./scripts/copilot-ai-gates/gates.ts
 *
 * Drives the REAL matcher and the REAL `isAiFallbackCandidate` rule the hook uses, never a copy, the
 * same way scripts/copilot-gates does. A turn that is not a candidate never calls the AI endpoint:
 * `send` in use-copilot.ts calls it only behind this rule (gate H5 pins that wiring).
 *
 * H1  DIRECT HELP REQUESTS NEVER REACH AI
 *     "help", "commands", "options", "what can you do" and their variants resolve to the help
 *     intent through a real match and keep the Copilot's own help reply.
 * H2  NO INTENT KEYWORD REACHES AI — every keyword of every intent.
 * H3  NO KNOWLEDGE OR COMPUTATION ARTICLE REACHES AI — every article's own question.
 * H4  A GENUINELY UNMATCHED QUESTION IS STILL OFFERED — the fallback stays reachable.
 * H5  THE HOOK CALLS AI ONLY BEHIND THIS RULE — a source check on use-copilot.ts.
 *
 * Exits non-zero if any gate fails.
 */
import { readFileSync } from "node:fs";
import { matchIntentWithEntity } from "@/modules/crm/features/copilot/engine/matcher";
import { isAiFallbackCandidate } from "@/modules/crm/features/copilot/engine/ai-fallback";
import { COMPUTATION_DOCS } from "@/modules/crm/features/copilot/engine/computations";
import knowledgeData from "@/modules/crm/features/copilot/data/knowledge.json";
import intentData from "@/modules/crm/features/copilot/data/intents.json";
import type { IntentConfig } from "@/modules/crm/features/copilot/engine/types";

const CONFIG = intentData as unknown as IntentConfig;
const candidate = (q: string) => isAiFallbackCandidate(matchIntentWithEntity(q, CONFIG), CONFIG);

let failed = 0;
function report(gate: string, ok: number, total: number, detail: string[] = []) {
  const pass = ok === total;
  if (!pass) failed++;
  console.log(`${pass ? "PASS" : "FAIL"}  ${gate.padEnd(46)} ${ok}/${total}`);
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
    if (match.intent.id === CONFIG.fallbackId && !isAiFallbackCandidate(match, CONFIG)) ok++;
    else bad.push(`<<${q}>> -> ${match.intent.id} score ${match.score}`);
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
      if (!candidate(kw)) ok++;
      else bad.push(`${intent.id} <<${kw}>>`);
    }
  }
  report("H2 no intent keyword reaches AI", ok, total, bad);
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
    if (!candidate(doc.question ?? "")) ok++;
    else bad.push(doc.id);
  }
  report("H3 no article question reaches AI", ok, articles.length, bad);
}

// ── H4 ──
{
  const unmatched = ["xyz qwerty"];
  const bad = unmatched.filter((q) => !candidate(q)).map((q) => `<<${q}>> not offered`);
  report("H4 unmatched question still offered to AI", unmatched.length - bad.length, unmatched.length, bad);
}

// ── H5 ──
{
  const hook = readFileSync(
    new URL("../../src/modules/crm/features/copilot/hooks/use-copilot.ts", import.meta.url),
    "utf8",
  );
  const checks: [string, boolean][] = [
    ["guarded by the flag and the rule", hook.includes("COPILOT_AI_ENABLED && isAiFallbackCandidate(match, INTENT_CONFIG)")],
    ["exactly one AI call site", (hook.match(/runAiFallback\(text/g) ?? []).length === 1],
  ];
  report(
    "H5 hook calls AI only behind the rule",
    checks.filter(([, ok]) => ok).length,
    checks.length,
    checks.filter(([, ok]) => !ok).map(([name]) => name),
  );
}

console.log(failed === 0 ? "\nAll AI-fallback gates passed." : `\n${failed} gate(s) FAILED.`);
process.exit(failed === 0 ? 0 : 1);
