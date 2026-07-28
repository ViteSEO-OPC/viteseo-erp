import type { ComputationEntry } from "@/modules/crm/features/copilot/engine/types";
import computationData from "@/modules/crm/features/copilot/data/computations.json";

/**
 * Explain Computation corpus — the single load point for data/computations.json.
 *
 * Both consumers read from here so the file is parsed and typed once: the matcher indexes
 * {@link COMPUTATION_DOCS} into the SAME MiniSearch index as the knowledge base (one retrieval
 * path, no second engine), and the response engine resolves a winning id back to its structured
 * entry through {@link COMPUTATION_BY_ID} to render it.
 *
 * Every entry is authored from the CRM's own computation specs and verified against the
 * implementation — `verifiedAgainst` records which. Entries whose `confidence` is `not-defined`
 * carry no formula on purpose: the Copilot states that the CRM does not compute the value rather
 * than inventing one.
 */
export const COMPUTATIONS = (computationData as { computations: ComputationEntry[] }).computations;

/** id → entry, for the `computation` response handler. */
export const COMPUTATION_BY_ID: ReadonlyMap<string, ComputationEntry> = new Map(
  COMPUTATIONS.map((entry) => [entry.id, entry]),
);

/**
 * Projection into the knowledge index's document shape, so computations are retrievable by the
 * existing MiniSearch pass with no change to how search works. The `answer` field concatenates the
 * prose fields purely so free-text terms inside them are searchable — it is NEVER rendered (the
 * handler renders the structured entry), which is why the loss of formatting here is harmless.
 */
export const COMPUTATION_DOCS = COMPUTATIONS.map((entry) => ({
  id: `computation:${entry.id}`,
  question: entry.question ?? `How is ${entry.label} computed?`,
  keywords: entry.keywords,
  answer: [entry.measures, entry.formula, entry.reasoning, entry.whyNot, ...(entry.caveats ?? [])]
    .filter(Boolean)
    .join(" "),
}));

/** True when a knowledge-index hit came from the computation corpus (see the `computation:` prefix). */
export function computationIdFromDocId(docId: string): string | null {
  return docId.startsWith("computation:") ? docId.slice("computation:".length) : null;
}
