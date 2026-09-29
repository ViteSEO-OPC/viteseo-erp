"use client";

import { useCallback } from "react";
import { apiClient } from "@/modules/crm/lib/axios";

/**
 * The Copilot's OPTIONAL AI fallback — off unless NEXT_PUBLIC_COPILOT_AI is "true" at build time.
 *
 * Inlined by Next at build, so with the flag off the Copilot never reaches this code and never
 * makes a request. The backend has its own switch (`copilot.ai.enabled`, also off by default); if
 * either is off, or anything fails, the Copilot keeps the help reply it has always given.
 */
export const COPILOT_AI_ENABLED = process.env.NEXT_PUBLIC_COPILOT_AI === "true";

type CopilotAnswer = { answered: boolean; text: string | null };

/**
 * Ask the AI fallback about a question the rule-based Copilot could not answer. Resolves to the
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
