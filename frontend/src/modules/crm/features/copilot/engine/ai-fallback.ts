import { normalize, type MatchResult } from "@/modules/crm/features/copilot/engine/matcher";
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
