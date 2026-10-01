"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";

/**
 * Settings → Integrations → Copilot AI.
 *
 * Status words only — the API never sends any part of the credential. The AI provider itself is the
 * Nearby card's OpenAI/OpenRouter credential; this screen switches the Copilot's USE of it on or off
 * and never changes the provider.
 */

/** Why AI is or is not available, naming the first gate that failed. */
export type CopilotAiReadiness =
  | "available"
  | "ops_disabled"
  | "toggle_off"
  | "not_configured"
  | "disabled"
  | "insufficient_credit"
  | "unverified";

export type CopilotAiSetting = {
  /** The Super Admin toggle. */
  aiEnabled: boolean;
  /** The server's ops kill switch (COPILOT_AI_ENABLED). */
  opsEnabled: boolean;
  /** The shared OpenAI/OpenRouter credential, in the Nearby card's vocabulary. */
  providerStatus: string;
  readiness: CopilotAiReadiness;
  /** All three gates passed: Copilot questions may use AI right now. */
  aiAvailable: boolean;
  updatedAt: string | null;
};

const ENDPOINT = "/settings/integrations/copilot";

export function useCopilotAiSetting() {
  const query = useQuery({
    queryKey: queryKeys.integrations.copilot(),
    queryFn: async (): Promise<CopilotAiSetting> => {
      const res = await apiClient.get(ENDPOINT);
      return res.data.data as CopilotAiSetting;
    },
  });

  // 403 is the answer, not a failure: the AI this switches on spends ViteSEO's own credit, so the
  // route is Super Admin only — same boundary as the Nearby provider settings.
  const status = (query.error as { response?: { status?: number } } | null)?.response?.status;

  return {
    setting: query.data,
    isLoading: query.isLoading,
    isError: query.isError && status !== 403,
    isForbidden: status === 403,
    retry: query.refetch,
  };
}

export function useUpdateCopilotAiSetting() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (aiEnabled: boolean): Promise<CopilotAiSetting> => {
      const res = await apiClient.put(ENDPOINT, { ai_enabled: aiEnabled });
      return res.data.data as CopilotAiSetting;
    },
    onSuccess: (data) => {
      queryClient.setQueryData(queryKeys.integrations.copilot(), data);
      // The boolean the Copilot itself reads.
      void queryClient.invalidateQueries({ queryKey: queryKeys.copilot.aiConfig() });
      if (!data.aiEnabled) {
        toast.success("Copilot AI turned off");
      } else if (data.aiAvailable) {
        toast.success("Copilot AI turned on");
      } else {
        toast.warning(
          "Copilot AI turned on, but not running",
          "The Copilot keeps its standard answers until the readiness check below passes.",
        );
      }
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Could not change the Copilot AI setting"));
    },
  });
}

/** Plain-language reason for the readiness line. */
export function readinessLabel(readiness: CopilotAiReadiness): string {
  switch (readiness) {
    case "available":
      return "Ready. Copilot questions without a standard answer may use AI.";
    case "ops_disabled":
      return "Turned off on this server (COPILOT_AI_ENABLED). The switch above has no effect until operations turns it on.";
    case "toggle_off":
      return "Off. The Copilot gives its standard answers only.";
    case "not_configured":
      return "No OpenAI/OpenRouter key is stored. Add one under Nearby Lead Generation.";
    case "disabled":
      return "The OpenAI/OpenRouter provider is switched off under Nearby Lead Generation.";
    case "insufficient_credit":
      return "The OpenAI/OpenRouter account has no credit left. Add credit, then run Test connection under Nearby Lead Generation.";
    case "unverified":
      return "The OpenAI/OpenRouter key has not passed Test connection under Nearby Lead Generation.";
  }
}
