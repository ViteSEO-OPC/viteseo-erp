import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";
import type { OrgSettings } from "@/modules/accounting/types";

const orgSettingsKey = ["accounting", "org-settings"] as const;

/** Readable by any member — the invoice form prefills its VAT rate from this. */
export function useOrgSettings() {
  return useQuery({
    queryKey: orgSettingsKey,
    queryFn: async (): Promise<OrgSettings> => {
      const { data } = await apiClient.get("/accounting/org-settings");
      return data.data as OrgSettings;
    },
  });
}

export function useUpdateOrgSettings() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: OrgSettings): Promise<OrgSettings> => {
      const { data } = await apiClient.put("/accounting/org-settings", payload);
      return data.data as OrgSettings;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: orgSettingsKey }),
  });
}
