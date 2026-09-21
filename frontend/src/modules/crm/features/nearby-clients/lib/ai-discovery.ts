/**
 * AI DISCOVERY — the per-run opt-in to AI search planning on the Google Places Stage 2 flow.
 *
 * THE FRONTEND SUPPLIES INTENT, NOTHING ELSE. It never decides how AI searches Google: it sends
 * `ai_strategy: true` beside the same locations and niches the existing form already collects, and the
 * backend plans, searches, validates and ingests. Every word shown about what AI did comes from the
 * run's own `ai.outcome`, recorded from what the engine reported — never from which button was pressed.
 *
 * PURE ON PURPOSE. No React, no HTTP client: the Nearby gates import this module directly, so the
 * request body, the capability rule and every UI state are pinned against the real code.
 */
import { PROVIDER_GOOGLE_PLACES } from "@/modules/crm/features/nearby-clients/lib/discovery-capability";
import type {
  DiscoveryRun,
  StartDiscoveryRunInput,
} from "@/modules/crm/features/nearby-clients/types/discovery-run-types";

/**
 * May the "Discover with AI" action be offered?
 *
 * BOTH conditions, matching the server's own gate: Google Places must be the provider actually
 * running (AI Discovery belongs to the Stage 2 flow only, so the OpenStreetMap default stays exactly as
 * it was), and the backend must report AI as `available`. A missing or still-loading status is not
 * permission — the action appears once the answer is in.
 */
export function canRunAiDiscovery(
  effectiveProvider: string | null | undefined,
  aiStatus: string | null | undefined,
): boolean {
  return effectiveProvider === PROVIDER_GOOGLE_PLACES && aiStatus === "available";
}

/**
 * The JSON body for `POST /discovery/runs`.
 *
 * `ai_strategy` IS SENT ONLY WHEN IT IS TRUE. An ordinary run's body is byte-identical to what the
 * dialog sent before AI Discovery existed — not `ai_strategy: false`, but no such key — so the default
 * flow cannot be changed by this feature even in the request it makes.
 *
 * The two modes keep their different shapes: a nationwide run sends no locations and no
 * `limit_per_task`, because its coverage and per-cell limit belong to the server.
 */
export function buildStartRunBody(input: StartDiscoveryRunInput): Record<string, unknown> {
  const body: Record<string, unknown> =
    input.scope === "nationwide"
      ? {
          scope: "nationwide",
          country: input.country,
          niches: input.niches,
          idempotency_key: input.idempotencyKey,
        }
      : {
          scope: "scoped",
          country: input.country,
          locations: input.locations,
          niches: input.niches,
          limit_per_task: input.limitPerTask,
          idempotency_key: input.idempotencyKey,
        };

  if (input.aiStrategy === true) body.ai_strategy = true;

  return body;
}

/** Every state the dialog can be in, from the user's side of the screen. */
export type DiscoveryPhase =
  | "idle"
  | "submitting"
  | "queued"
  | "running"
  | "completed"
  | "cancelled"
  | "failed"
  | "insufficient_credit";

/**
 * Where the dialog is, derived from the request in flight and the polled run.
 *
 * `insufficient_credit` has two sources and both are real: the backend refused to START an AI run
 * because the account has no credit (a 422, before anything was spent), or a run that asked for AI
 * learned mid-run that the account could not pay and stopped. It outranks `failed` because it names
 * the cause and the fix.
 */
export function discoveryPhase(args: {
  submitting: boolean;
  run: Pick<DiscoveryRun, "status" | "ai"> | undefined;
  /** The start request was refused on `ai_strategy` and the capability now reads no credit. */
  aiStartRefusedForCredit?: boolean;
}): DiscoveryPhase {
  const { submitting, run, aiStartRefusedForCredit } = args;

  if (run === undefined) {
    if (submitting) return "submitting";
    return aiStartRefusedForCredit === true ? "insufficient_credit" : "idle";
  }

  if (run.ai?.requested === true && run.ai.outcome === "insufficient_credit") {
    return "insufficient_credit";
  }

  switch (run.status) {
    case "pending":
      return "queued";
    case "running":
      return "running";
    case "completed":
      return "completed";
    case "cancelled":
      return "cancelled";
    case "failed":
      return "failed";
  }
}

/**
 * One truthful sentence about AI for a run — or null when the run never asked for AI and AI did not
 * shape it, so an ordinary run's panel is exactly what it was.
 *
 * WHAT IS NEVER SAID. Not how the model scored or ranked anything, not which phrasings it chose and
 * none of the internal feedback verdicts: those are engine mechanics, not results. While a run is
 * still going, `not_applied` means "not yet", so it is described as pending rather than as a verdict.
 */
export function aiOutcomeMessage(
  run: Pick<DiscoveryRun, "ai" | "isTerminal"> | undefined,
): string | null {
  const ai = run?.ai;
  if (ai === undefined) return null;

  if (ai.outcome === "applied") {
    return "AI planned how these searches were phrased. Every business still came from Google Places.";
  }
  if (!ai.requested) return null;

  if (ai.outcome === "insufficient_credit") {
    return "AI planning could not run because the AI account has no credit. The run was stopped rather than continued as a plain Google search.";
  }
  return run?.isTerminal === true
    ? "AI planning was requested but did not change these searches, so they ran as standard Google Places searches."
    : "AI planning requested — results are reported when the searches finish.";
}

/** A short label for the AI row in the run panel. */
export function aiOutcomeLabel(run: Pick<DiscoveryRun, "ai" | "isTerminal"> | undefined): string | null {
  const ai = run?.ai;
  if (ai === undefined || (!ai.requested && ai.outcome !== "applied")) return null;

  if (ai.outcome === "applied") return "Applied";
  if (ai.outcome === "insufficient_credit") return "No AI credit";
  return run?.isTerminal === true ? "Not applied" : "Requested";
}

/**
 * Was this failed start refused because AI is unavailable (rather than any other validation)?
 * Reads the standard `{ message, errors }` envelope; the backend keys the refusal on `ai_strategy`.
 */
export function isAiStartRefusal(err: unknown): boolean {
  if (typeof err !== "object" || err === null) return false;
  const response = (err as { response?: { status?: number; data?: { errors?: Record<string, unknown> } } })
    .response;
  return response?.status === 422 && response.data?.errors?.ai_strategy !== undefined;
}
