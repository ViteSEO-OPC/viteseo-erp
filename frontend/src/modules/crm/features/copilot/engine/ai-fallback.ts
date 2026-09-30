import type { MatchResult } from "@/modules/crm/features/copilot/engine/matcher";
import type { IntentConfig } from "@/modules/crm/features/copilot/engine/types";

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
