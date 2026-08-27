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
 * How much of the question an intent's own keywords must account for before that intent may win the
 * second retrieval tier. This is the tier's ENTIRE safety mechanism, and it is not a relevance knob.
 *
 * Token overlap alone is not evidence that a question was understood: "sino kailangan kong balikan"
 * shares `kailangan`/`kong` with "ano kailangan kong gawin today" while asking something the CRM has
 * no definition for. Requiring the keywords to EXPLAIN most of the question rejects that, and the
 * threshold is where it does so: measured across the labelled matrix, 0.70 keeps all five deferred
 * concepts unanswered while 0.60 answers two of them with confident, plausible, wrong replies.
 * Lower this and the Copilot starts inventing.
 */
const INTENT_COVERAGE_MIN = 0.7;

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

    for (const hit of hits) {
      const score = hit.score ?? 1;
      if (score > INTENT_CONFIDENCE_MAX_SCORE) break;

      // A per-lead intent needs a name to look up. Without its connector present, the frame only
      // resembles the question — so step past it and let the general reading win instead.
      if (
        hit.item.intent.response.type === "lead_lookup" &&
        !frameIntroducesName(hit.item.keyword, message)
      ) {
        continue;
      }

      // Convert Fuse's 0-is-best score to our higher-is-better convention.
      return { intent: hit.item.intent, score: round(1 - score) };
    }

    // No confident command intent → CRM knowledge search.
    const knowledge = searchKnowledge(query, config);
    if (knowledge) return knowledge;
  }

  return { intent: resolveFallback(config), score: 0 };
}

/**
 * Does this keyword's frame actually introduce a NAME in this message?
 *
 * Every `lead_lookup` keyword is a frame with an empty slot at the end — "may meeting ba ako **kay**",
 * "recent activity **on**", "how is … **doing**" — and the final token is the connector that the
 * company name follows. Fuse scores by edit distance, so a general question that merely resembles
 * the frame matches it cheaply: "may meeting ba ako **bukas**" differs from "may meeting ba ako kay"
 * by one short token and scores 0.395, beating `schedule-meetings-today` at 0.449. The Copilot then
 * searched the CRM for a lead called "bukas" and reported it could not find one.
 *
 * The same slip let the entity rescue answer "How is Conversion Rate computed?" with `lead-about`:
 * its frame "how is" matched "how is … doing" while "doing" was nowhere in the message.
 *
 * Requiring the connector to be PRESENT is the evidence that was missing — a name really was
 * introduced, rather than the frame being approximated. Measured over the whole corpus this rejects
 * all 14 misrouted computation articles and every greedy natural-language case, while accepting
 * every genuine lead question, including partial frames ("status ng Acme" against "ano status ng",
 * where the connector "ng" is present and only the leading question word was dropped).
 */
function frameIntroducesName(keyword: string, message: string): boolean {
  const tokens = keyword.split(" ").filter(Boolean);
  const connector = tokens[tokens.length - 1];
  if (connector === undefined) return false;

  return new Set(normalize(message).split(" ")).has(connector);
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

/**
 * One retrieval document. `article` docs are the knowledge + computation corpus, unchanged.
 * `intent` docs project an intent's own keywords into the SAME index so a long natural-language
 * question can be ranked against commands and articles together, on one comparable footing.
 */
type RetrievalDoc = KnowledgeDoc & { kind: "article" | "intent"; intentId?: string };

// The knowledge base plus the Explain Computation corpus, projected into the same document shape.
// ONE index and ONE retrieval path: a "how is X computed" query never has to guess which corpus to
// search, and the search configuration below is unchanged. Computation docs are distinguished only
// by their `computation:` id prefix, which the hit handler uses to render them structurally.
const KNOWLEDGE_BASE: ReadonlyArray<RetrievalDoc> = [
  ...(knowledgeData.knowledge as ReadonlyArray<KnowledgeDoc>),
  ...COMPUTATION_DOCS,
].map((doc) => ({ ...(doc as KnowledgeDoc), kind: "article" as const }));

/** An intent, projected into the retrieval corpus by its own keywords. */
function intentDoc(intent: Intent): RetrievalDoc {
  return {
    id: `intent:${intent.id}`,
    kind: "intent",
    intentId: intent.id,
    // The keywords carry the question field's ×3 boost, matching how an article's `question` works.
    question: intent.keywords.join(" . "),
    keywords: intent.keywords.join(" "),
    answer: "",
  };
}

// Keyed by config identity (a stable module constant), like the Fuse index — so the corpus is
// indexed once, not per turn.
const retrievalIndexCache = new WeakMap<IntentConfig, MiniSearch<RetrievalDoc>>();

function getKnowledgeIndex(config: IntentConfig): MiniSearch<RetrievalDoc> {
  const cached = retrievalIndexCache.get(config);
  if (cached) return cached;

  const index = new MiniSearch<RetrievalDoc>({
    idField: "id",
    fields: ["question", "keywords", "answer"],
    storeFields: ["answer", "kind", "intentId"],
    searchOptions: {
      fuzzy: 0.2,
      prefix: true,
      boost: { question: 3, keywords: 2 },
    },
  });
  index.addAll([...KNOWLEDGE_BASE, ...config.intents.map(intentDoc)]);

  retrievalIndexCache.set(config, index);
  return index;
}

// Token vocabulary per intent, for the coverage gate. Cached per config alongside the index.
const vocabCache = new WeakMap<IntentConfig, Map<string, Set<string>>>();

function getVocab(config: IntentConfig): Map<string, Set<string>> {
  const cached = vocabCache.get(config);
  if (cached) return cached;

  const vocab = new Map<string, Set<string>>();
  for (const intent of config.intents) {
    vocab.set(intent.id, new Set(intent.keywords.flatMap((k) => normalize(k).split(" "))));
  }
  vocabCache.set(config, vocab);
  return vocab;
}

/**
 * The fraction of the question's own tokens that this intent's keywords account for.
 *
 * Prefix-tolerant in both directions so Tagalog inflections still count ("gagawin" ↔ "gawin"),
 * which is the same tolerance the MiniSearch `prefix` option already applies to retrieval.
 */
function coverage(query: string, intentId: string, config: IntentConfig): number {
  const tokens = query.split(" ").filter((t) => t.length > 1);
  if (tokens.length === 0) return 0;

  const vocab = getVocab(config).get(intentId);
  if (!vocab) return 0;

  const explained = tokens.filter(
    (t) => vocab.has(t) || [...vocab].some((v) => v.startsWith(t) || t.startsWith(v)),
  ).length;

  return explained / tokens.length;
}

/**
 * Full-text search over the CRM knowledge base. On a relevant hit, returns a synthesized `text`
 * intent carrying the answer — rendered by the existing response engine with no changes. Returns
 * null when nothing is relevant enough, so the caller can fall through to the help intent.
 */
function searchKnowledge(query: string, config: IntentConfig): MatchResult | null {
  // ARTICLES ONLY — intent docs share the index but are invisible to this path, so Tier 1's
  // fallback behaviour is exactly what it was before they were added.
  const top = getKnowledgeIndex(config)
    .search(query)
    .find((hit) => (hit as unknown as RetrievalDoc).kind !== "intent");
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
  "action","last","contact","contacted","spoke","talked","talk","to","on","did","when","was","lead","leads",
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
function resolveWithEntity(message: string, config: IntentConfig): MatchResult {
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
    best.item.intent.response.type === "lead_lookup" &&
    frameIntroducesName(best.item.keyword, message)
  ) {
    return { intent: best.item.intent, score: round(1 - (best.score ?? 1)) };
  }

  return primary;
}

/** A turn the Copilot did not actually resolve: a synthesized article reply, or the fallback. */
function isUnresolved(intent: Intent, config: IntentConfig): boolean {
  return intent.id.startsWith("knowledge:") || intent.id === config.fallbackId;
}

/**
 * SECOND RETRIEVAL TIER — the only path that can answer a long natural-language question.
 *
 * Fuse is an edit-distance matcher: it scores the WHOLE message as a pattern against each short
 * keyword, so every extra word a user types inflates the distance regardless of where it sits. A
 * five-to-nine-token Taglish question therefore cannot clear the 0.45 bar even when the right intent
 * is Fuse's top candidate — "ano mga kailangan kong asikasuhin today" ranks `schedule-todo-today`
 * first, at 0.567. MiniSearch is token-based, so extra words simply fail to contribute instead of
 * penalising, which is exactly the property the long-question case needs.
 *
 * Intents are ranked in the SAME index as the articles, so neither corpus is privileged: if an
 * article genuinely outranks every intent, the question really was a knowledge question and Tier 1's
 * answer stands.
 *
 * {@link INTENT_COVERAGE_MIN} is what keeps this honest. Retrieval relevance alone would answer
 * "sino kailangan kong balikan" with today's Planner items — plausible, confident, and not what was
 * asked. Requiring the intent's keywords to account for most of the question rejects that, and the
 * CRM has no canonical definition for it to answer from anyway.
 */
function searchIntentByCoverage(query: string, config: IntentConfig): MatchResult | null {
  if (!query) return null;

  for (const hit of getKnowledgeIndex(config).search(query)) {
    if (hit.score < KNOWLEDGE_MIN_SCORE) break;

    const doc = hit as unknown as RetrievalDoc;
    // An article outranking every intent means Tier 1 already gave the right kind of answer.
    if (doc.kind !== "intent") return null;
    if (!doc.intentId) continue;

    // NEVER a per-lead intent. This tier ranks by token overlap and has no notion of the name slot,
    // so it cannot tell "may follow up ba ako kay <company>" from "may follow up ba ako today" — it
    // would hand the lead search a word like "today" as a company name. Lead questions are already
    // served by Tier 1 and the entity rescue, both of which check that a name was actually named.
    const candidate = findIntent(doc.intentId, config);
    if (!candidate || candidate.response.type === "lead_lookup") continue;
    // Ranked above the articles, but it does not explain the question — keep looking.
    if (coverage(query, doc.intentId, config) < INTENT_COVERAGE_MIN) continue;

    return { intent: candidate, score: round(hit.score) };
  }

  return null;
}

/**
 * Intent matching for messages that may name a lead, plus the natural-language second tier.
 *
 * The existing resolution runs FIRST and unchanged, so every message it already answers takes the
 * identical path it always did. Tier 2 is consulted only when that produced no answer at all — a
 * synthesized article or the fallback — so it can upgrade a non-answer but can never re-route one.
 */
export function matchIntentWithEntity(message: string, config: IntentConfig): MatchResult {
  const primary = resolveWithEntity(message, config);
  if (!isUnresolved(primary.intent, config)) return primary;

  return searchIntentByCoverage(normalize(message), config) ?? primary;
}
