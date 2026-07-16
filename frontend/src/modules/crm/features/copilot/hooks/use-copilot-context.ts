import { useMemo } from "react";
import type { CopilotContext } from "@/modules/crm/features/copilot/engine/types";

/**
 * The single frontend data source the Copilot reads for any dynamic CRM figure or record
 * (hot-lead recommendations, follow-ups, drafts, counts). It is DELIBERATELY empty.
 *
 * The Copilot must never invent leads, companies, scores, or counts. Because there is no
 * ownership-scoped frontend lead source available without a backend call (a project constraint),
 * every collection here is empty and every metric is 0 — nothing is fabricated. The engine's
 * data-backed responses treat an empty snapshot as "no data" and fall back to generic workflow
 * guidance instead of example records.
 *
 * This hook is the ONLY wiring point: when a real frontend lead source becomes available, map it
 * into these fields and the same responses will render real recommendations — with no change to
 * the engine, the JSON, or the UI.
 */
const EMPTY_CONTEXT: CopilotContext = {
  metrics: { hotLeads: 0, followUps: 0, drafts: 0, todayCalls: 0, newLeads: 0, assignedLeads: 0 },
  topLeads: [],
  followUpsList: [],
  draftEmail: { to: "", subject: "", body: "" },
  summary: { newLeads: 0, contacted: 0, followUps: 0, conversion: "" },
  scoring: { scored: 0, movedToHigh: 0 },
};

export function useCopilotContext(): CopilotContext {
  // Stable identity across renders. Swap the source here (not the engine) to go live.
  return useMemo(() => EMPTY_CONTEXT, []);
}
