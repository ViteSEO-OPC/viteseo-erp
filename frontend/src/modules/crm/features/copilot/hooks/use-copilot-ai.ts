"use client";

import { useCallback } from "react";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";

/**
 * May the Copilot offer questions to its OPTIONAL AI right now?
 *
 * Decided on the server, never here: the ops kill switch, the Super Admin toggle (Settings →
 * Integrations → Copilot AI) and the shared credential must all allow it, and the Copilot only reads
 * the resulting boolean. Anything short of an explicit `true` — still loading, refused, failed —
 * reads as OFF, so the Copilot keeps the deterministic replies it has always given and sends no AI
 * request.
 */
export function useCopilotAiAvailable(): boolean {
  const query = useQuery({
    queryKey: queryKeys.copilot.aiConfig(),
    queryFn: async (): Promise<boolean> => {
      const response = await apiClient.get("/copilot/config");
      return response.data?.data?.aiAvailable === true;
    },
    staleTime: 60_000,
    retry: false,
  });

  return query.data === true;
}

type CopilotAnswer = { answered: boolean; text: string | null };

/**
 * Ask the AI about a question the rule-based Copilot could not answer. Resolves to the
 * answer text, or null for "not answered" — declined, out of scope, switched off, or failed.
 * Only the question is sent; the server builds the CRM context for the signed-in user.
 */
export function useCopilotAi() {
  return useCallback(async (question: string): Promise<string | null> => {
    try {
      const response = await apiClient.post("/copilot/ask", { question });
      const answer = response.data?.data as CopilotAnswer | undefined;
      const text = answer?.answered === true && typeof answer.text === "string" ? answer.text.trim() : "";
      return text === "" ? null : text;
    } catch {
      return null;
    }
  }, []);
}
