import Fuse from "fuse.js";
import MiniSearch from "minisearch";
import type { Intent, IntentConfig } from "@/modules/crm/features/copilot/engine/types";
import knowledgeData from "@/modules/crm/features/copilot/data/knowledge.json";
import {
  COMPUTATION_DOCS,
  computationIdFromDocId,
} from "@/modules/crm/features/copilot/engine/computations";

/**
 * Normalize free text for matching: lowercase, strip punctuation/symbols (Unicode-aware),
 * and collapse whitespace. "Who should I call today?" → "who should i call today".
 */
export function normalize(text: string): string {
  return text
    .toLowerCase()
    .replace(/[^\p{L}\p{N}\s]/gu, " ")
    .replace(/\s+/g, " ")
    .trim();
}

export type MatchResult = {
  intent: Intent;
  score: number; // higher = more confident; 0 when only the fallback matched
};

// ── Tuning knobs ──────────────────────────────────────────────────────────────────────────────
// Fuse score runs 0 (perfect) → 1 (worst). `RETRIEVE` is how permissive Fuse is when returning
// candidates; `CONFIDENCE` is the accept bar — a candidate above this bar is treated as "no
// confident intent" and the query is routed to the CRM knowledge search. Kept as named constants
// so behaviour is tunable without touching logic.
//
// The index scores each keyword/example INDEPENDENTLY (one Fuse document per keyword — see
// getIntentIndex), so an intent's score reflects its single best-matching example and is immune to
// how many examples it has. Calibration: a genuine example match (exact, a typo, or an extra word
// like "compose an email" → "compose email") scores ≤ ~0.42, while a question that merely shares a
// word with an example ("how do I import *leads*", "what is a *lead* score") scores ≥ ~0.6. The
// 0.45 bar cleanly separates the two, so those questions fall through to the knowledge search
// instead of firing a lead command.
const FUSE_RETRIEVE_THRESHOLD = 0.6;
const INTENT_CONFIDENCE_MAX_SCORE = 0.45;
// MiniSearch relevance is unbounded-positive (higher = better); ignore weak, noisy hits.
const KNOWLEDGE_MIN_SCORE = 1;

/**
 * Rule-based intent matcher (Fuse.js-powered). Fuzzy-matches the message against each intent's
 * keywords, tolerating typos and word-order/inflection differences that the old exact-substring
 * matcher missed. When no intent clears the confidence bar, it falls back to a MiniSearch full-text
 * search over the CRM knowledge base and synthesizes a `text` intent from the best answer (which
 * flows through the UNCHANGED response engine). If knowledge search also comes up empty, it returns
 * the configured fallback intent — identical behaviour to before.
 *
 * The signature and return type are unchanged, so every caller (the hook, the response pipeline)
 * is untouched.
 */
export function matchIntent(message: string, config: IntentConfig): MatchResult {
  const query = normalize(message);

  if (query) {
    const hits = getIntentIndex(config).search(query);
    const best = hits[0];
    const bestScore = best?.score ?? 1;

    if (best && bestScore <= INTENT_CONFIDENCE_MAX_SCORE) {
      // Convert Fuse's 0-is-best score to our higher-is-better convention.
      return { intent: best.item.intent, score: round(1 - bestScore) };
    }

    // No confident command intent → CRM knowledge search.
    const knowledge = searchKnowledge(query);
    if (knowledge) return knowledge;
  }

  return { intent: resolveFallback(config), score: 0 };
}

/** Look up an intent by id — used by quick-actions, which bind directly to an intent. */
export function findIntent(id: string, config: IntentConfig): Intent | undefined {
  return config.intents.find((intent) => intent.id === id);
}

// ── Fuse intent index ──────────────────────────────────────────────────────────────────────────

// One document PER keyword (not one document per intent with an array of keywords). Flattening this
// way makes Fuse score every example on its own merit and pick the single best across all intents —
// so a match reflects the closest example, and adding more examples to an intent never inflates (or
// deflates) its score. An array-valued key, by contrast, lets Fuse's field aggregation reward
// intents that simply have many keywords, which caused unrelated queries (e.g. "how do I import
// leads") to match a lead intent. The winning document's `intent` is the resolved intent.
type IntentDoc = { intent: Intent; keyword: string };

// Built once per config identity (the config is a stable module constant), so the index is not
// rebuilt on every keystroke/turn.
const intentIndexCache = new WeakMap<IntentConfig, Fuse<IntentDoc>>();

function getIntentIndex(config: IntentConfig): Fuse<IntentDoc> {
  const cached = intentIndexCache.get(config);
  if (cached) return cached;

  const docs: IntentDoc[] = config.intents.flatMap((intent) =>
    intent.keywords.map((keyword) => ({ intent, keyword: normalize(keyword) })),
  );

  const fuse = new Fuse(docs, {
    keys: ["keyword"],
    includeScore: true,
    threshold: FUSE_RETRIEVE_THRESHOLD,
    ignoreLocation: true, // match anywhere in the keyword, not just the start
    minMatchCharLength: 2,
  });

  intentIndexCache.set(config, fuse);
  return fuse;
}

// ── MiniSearch CRM knowledge fallback ────────────────────────────────────────────────────────

type KnowledgeDoc = {
  id: string;
  question: string;
  keywords: string;
  answer: string;
};

// The knowledge base plus the Explain Computation corpus, projected into the same document shape.
// ONE index and ONE retrieval path: a "how is X computed" query never has to guess which corpus to
// search, and the search configuration below is unchanged. Computation docs are distinguished only
// by their `computation:` id prefix, which the hit handler uses to render them structurally.
const KNOWLEDGE_BASE = [
  ...(knowledgeData.knowledge as ReadonlyArray<KnowledgeDoc>),
  ...COMPUTATION_DOCS,
] as ReadonlyArray<KnowledgeDoc>;

// Singleton index — the knowledge base is a static module constant, so it's indexed once.
let knowledgeIndex: MiniSearch<KnowledgeDoc> | null = null;

function getKnowledgeIndex(): MiniSearch<KnowledgeDoc> {
  if (knowledgeIndex) return knowledgeIndex;

  const index = new MiniSearch<KnowledgeDoc>({
    idField: "id",
    fields: ["question", "keywords", "answer"],
    storeFields: ["answer"],
    searchOptions: {
      fuzzy: 0.2,
      prefix: true,
      boost: { question: 3, keywords: 2 },
    },
  });
  index.addAll(KNOWLEDGE_BASE as KnowledgeDoc[]);

  knowledgeIndex = index;
  return index;
}

/**
 * Full-text search over the CRM knowledge base. On a relevant hit, returns a synthesized `text`
 * intent carrying the answer — rendered by the existing response engine with no changes. Returns
 * null when nothing is relevant enough, so the caller can fall through to the help intent.
 */
function searchKnowledge(query: string): MatchResult | null {
  const top = getKnowledgeIndex().search(query)[0];
  if (!top || top.score < KNOWLEDGE_MIN_SCORE) return null;

  // A computation hit is rendered STRUCTURALLY (formula / numerator / denominator / reasoning) by
  // the `computation` handler, so the synthesized intent carries the id rather than prose. Ordinary
  // knowledge hits keep their previous shape byte-for-byte.
  const computationId = computationIdFromDocId(String(top.id));
  const synthetic: Intent = {
    id: `knowledge:${top.id}`,
    keywords: [],
    response: computationId
      ? { type: "computation", computationId }
      : { type: "text", text: String(top.answer) },
  };
  return { intent: synthetic, score: round(top.score) };
}

// ── Shared helpers ───────────────────────────────────────────────────────────────────────────

function resolveFallback(config: IntentConfig): Intent {
  const fallback = findIntent(config.fallbackId, config) ?? config.intents[0];
  if (fallback) return fallback;
  // Degenerate config with no intents — never happens with the shipped JSON, but keeps the
  // return type honest (intents are index-accessed under noUncheckedIndexedAccess).
  return {
    id: "empty",
    keywords: [],
    response: { type: "text", text: "I'm not sure how to help with that yet." },
  };
}

function round(value: number): number {
  return Math.round(value * 1000) / 1000;
}

/**
 * Words that carry the question, not the company — stripped so what remains is the entity.
 *
 * Both languages, because the CRM is used in Taglish: "kamusta yung ABC Company" and "how is ABC
 * Company doing" must both leave "abc company". Deliberately a small closed list of question and
 * filler words, NOT a general parser — anything it fails to strip is simply passed to the lead
 * search, which is fuzzy server-side and policy-scoped, so a sloppy term costs a miss rather than a
 * wrong answer.
 */
const ENTITY_STOPWORDS = new Set([
  // English question/filler
  "how","is","are","the","a","an","about","of","for","with","status","doing","what","whats","tell",
  "me","my","do","i","have","any","meeting","meetings","follow","followup","followups","up","next",
  "action","last","contact","contacted","spoke","talked","to","on","did","when","was","lead","leads",
  // "last activity on X" / "recent activity on X" — question words, never part of a company name.
  "activity","recent",
  "company","update","updates","show","give",
  // Taglish question/filler
  "kamusta","kumusta","yung","yong","ang","si","kay","ba","ako","ko","may","meron","mayroon","ano",
  "anong","na","ng","sa","kailan","huling","nakausap","kausap","susunod","gagawin","dapat","po",
  "yan","ito","natin","namin",
]);

/**
 * The company/lead phrase a message is asking about, or "" when there is none.
 *
 * Token-subtractive rather than pattern-based: normalize, drop the words that form the question, and
 * whatever survives is the entity. That keeps every company name OUT of the client bundle — the
 * alternative (indexing lead names for Fuse) would ship the whole book of business to every browser
 * regardless of who may see it, and go stale on every new lead. Resolution belongs to the
 * policy-scoped `GET /leads?search=`, which already answers per-caller.
 */
export function extractEntityPhrase(message: string): string {
  return normalize(message)
    .replace(/[?!.,]/g, " ")
    .split(/\s+/)
    .filter((w) => w.length > 0 && !ENTITY_STOPWORDS.has(w))
    .join(" ")
    .trim();
}

/**
 * The question WITHOUT the entity — "kamusta yung Heritage Designs Studio" → "kamusta yung".
 *
 * The complement of {@link extractEntityPhrase}, and the reason both exist. Fuse scores a whole
 * message against each keyword, so a company name the keywords cannot possibly contain inflates the
 * distance and pushes a genuine match past the 0.45 confidence bar — "kamusta yung X Y Z" scored
 * worse than an unrelated knowledge article. Matching the frame alone restores the comparison the
 * keywords were written for.
 */
export function questionFrame(message: string): string {
  return normalize(message)
    .replace(/[?!.,]/g, " ")
    .split(/\s+/)
    .filter((w) => ENTITY_STOPWORDS.has(w))
    .join(" ")
    .trim();
}

/**
 * Intent matching for messages that may name a lead.
 *
 * Runs the ordinary {@link matchIntent} first, so every existing intent behaves exactly as before.
 * Only when that produces no confident command AND the message contains something that looks like an
 * entity does it retry against the {@link questionFrame} — and it accepts that second result ONLY if
 * it is a lead intent. A question with no company in it can therefore never be re-routed by this
 * path, and the 36 pre-existing intents are unaffected either way.
 */
export function matchIntentWithEntity(message: string, config: IntentConfig): MatchResult {
  const primary = matchIntent(message, config);
  if (primary.intent.response.type === "lead_lookup") return primary;

  // A confident non-lead COMMAND wins outright — "go to leads" must not become a lead lookup.
  //
  // A knowledge answer does not, and the distinction matters: `searchKnowledge` is already the
  // fallback for "no confident intent", and its MiniSearch relevance is unbounded, so an article
  // that merely shares a word with the company name ("Heritage Designs *Studio*") outscores the real
  // question. Synthesized knowledge intents are marked by a `knowledge:` id, so they stay
  // overridable by a genuine entity match while real commands do not.
  const primaryIsKnowledge = primary.intent.id.startsWith("knowledge:");
  if (!primaryIsKnowledge && primary.score >= round(1 - INTENT_CONFIDENCE_MAX_SCORE)) return primary;

  const entity = extractEntityPhrase(message);
  const frame = questionFrame(message);
  if (!entity || !frame) return primary;

  const hits = getIntentIndex(config).search(frame);
  const best = hits[0];
  if (
    best &&
    (best.score ?? 1) <= INTENT_CONFIDENCE_MAX_SCORE &&
    best.item.intent.response.type === "lead_lookup"
  ) {
    return { intent: best.item.intent, score: round(1 - (best.score ?? 1)) };
  }

  return primary;
}
