import { extractEntityPhrase, normalize, type MatchResult } from "@/modules/crm/features/copilot/engine/matcher";
import type { Intent, IntentConfig } from "@/modules/crm/features/copilot/engine/types";

/**
 * May this free-text turn be offered to the optional AI fallback?
 *
 * Only when the matcher resolved NOTHING: the fallback intent at score 0, which is the matcher's own
 * contract for "only the fallback matched". A question that ASKS for help — "help", "commands",
 * "what can you do" — resolves to the same intent through a real keyword or coverage match, so it
 * scores above 0 and keeps the Copilot's own help reply with no provider call. Every other intent
 * and every knowledge article is never a candidate.
 */
export function isAiFallbackCandidate(match: MatchResult, config: IntentConfig): boolean {
  return match.intent.id === config.fallbackId && match.score === 0;
}

/**
 * With AI switched ON, may this free-text turn be offered to the AI?
 *
 * A genuine fallback ({@link isAiFallbackCandidate}), or a synthesized knowledge-article match — the
 * matcher's `knowledge:` id, which is where natural-language questions land ("analyze my pipeline",
 * "compare my priorities"). If the AI declines or fails, the Copilot shows that same article.
 *
 * Every real intent — navigation, live data, lead lookup, drafting, schedule — and a direct request
 * for help stay deterministic and are never offered.
 */
export function isAiEligible(match: MatchResult, config: IntentConfig): boolean {
  return isAiFallbackCandidate(match, config) || match.intent.id.startsWith("knowledge:");
}

/** The only lead intent whose open-ended questions may reach the AI (PO decision D1, 2026-09-30). */
export const LEAD_AI_INTENT_ID = "lead-about";

/**
 * `lead-about` phrasings that ask for one exact field. They keep the rule-based reply, which already
 * answers them from the same lead row, and are never offered to the AI.
 */
export const LEAD_EXACT_PHRASES = ["status of", "ano status ng"] as const;

/**
 * With AI switched ON, may this LEAD turn be offered to the AI, about the one lead the lookup found?
 *
 * Only an open-ended `lead-about` question ("tell me about Kopi", "how is Kopi doing", "kamusta si
 * Kopi") AND only when the policy-scoped lead search resolved exactly ONE lead. An exact phrasing
 * ({@link LEAD_EXACT_PHRASES}), every other lead intent (follow-up, meeting, next action, last
 * contact), and a lookup that found no lead or several stay deterministic. The server re-authorises
 * the lead regardless; this rule only decides when to ask.
 */
export function isLeadAiEligible(intent: Intent, message: string, resolvedLeads: number): boolean {
  if (intent.id !== LEAD_AI_INTENT_ID || resolvedLeads !== 1) return false;
  const text = ` ${normalize(message)} `;
  return !LEAD_EXACT_PHRASES.some((phrase) => text.includes(` ${normalize(phrase)} `));
}

/**
 * The lead id as the server accepts it — a strict positive integer — or null, in which case the
 * turn stays deterministic. Never guessed or coerced from anything but the lookup's own id.
 */
export function leadIdForAi(id: string | number): number | null {
  const n = typeof id === "number" ? id : Number(id);
  return Number.isSafeInteger(n) && n > 0 ? n : null;
}

/**
 * The lead whose detail page is open — `/zerem/leads/{id}` exactly — as the server accepts it, or
 * null on any other page. Read from the URL at send time, so it is always the page in view. The URL
 * is untrusted: the server re-authorises this id exactly as any other `lead_id` (Slice 1).
 */
export function pageLeadId(pathname: string): number | null {
  const match = /^\/zerem\/leads\/(\d+)\/?$/.exec(pathname);
  return match ? leadIdForAi(match[1]) : null;
}

/** "this lead" and its equivalents — the question points at a lead instead of naming one. */
const PAGE_LEAD_REFERENCE =
  /\b(?:this (?:lead|one|company|business|client|account|prospect)|(?:itong|ang|yung) (?:lead|company|kliyente|client)(?: na (?:ito|to))?|lead na (?:ito|to))\b/;

/** An open-ended ask about the whole lead. */
const PAGE_LEAD_OPEN =
  /\b(?:tell me about|how is|how s|hows|what about|kamusta|kumusta|summari[sz]e|summary of|overview of|update on|what do you know about|describe)\b/;

/**
 * One exact field. These keep their rule-based reply, unchanged (PO decision D-a, 2026-10-01): the
 * page's lead is never attached to them in this slice.
 */
const PAGE_LEAD_EXACT =
  /\b(?:status|activity|activities|contact|contacted|follow|followup|followups|meeting|meetings|next action|next step|susunod|kailan|huling)\b/;

/** Question words the entity extractor does not already drop. */
const PAGE_LEAD_FILLER = new Set([
  "summarize", "summarise", "summary", "overview", "describe", "know", "you", "can", "could",
  "please", "pls", "quick", "brief", "s", "hows", "kumusta", "yung", "itong", "to",
]);

/**
 * An open-ended question about "this lead" that names no lead of its own: "Tell me about this
 * lead", "how is this one doing", "kamusta ang lead na ito". Only such a question may be answered
 * from the open lead page; a question that names a company ("tell me about Kopi") keeps the
 * name-lookup path, so the page's lead is never put in place of a lead the user named. Exact
 * fields (status, last activity, follow-ups, meetings, next action) are never page-lead questions.
 */
export function isPageLeadQuestion(message: string): boolean {
  const text = normalize(message);
  if (!PAGE_LEAD_REFERENCE.test(text) || !PAGE_LEAD_OPEN.test(text) || PAGE_LEAD_EXACT.test(text)) return false;
  const rest = text
    .replace(new RegExp(PAGE_LEAD_REFERENCE.source, "g"), " ")
    .split(" ")
    .filter((word) => word !== "" && !PAGE_LEAD_FILLER.has(word))
    .join(" ");
  return extractEntityPhrase(rest) === "";
}

/** Shown when the open lead page's AI answer is unavailable (PO decision D-c, 2026-10-01). */
export const PAGE_LEAD_FALLBACK = "I couldn't summarize this lead. Please open its Timeline for more details.";

/**
 * Shown for a "this lead" question asked away from a lead page, which has no lead to point at
 * (PO decision D-b, 2026-10-01) — instead of an answer about the whole portfolio.
 */
export const NO_PAGE_LEAD_REPLY =
  "I can't tell which lead you mean here. Open the lead's page and ask again, or name the company in your question.";

