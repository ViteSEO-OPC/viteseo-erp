import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";
import type { Attachment } from "@/modules/accounting/types";

type Attachable = "invoice" | "bill";

export function useAttachments(type: Attachable, id: number | null) {
  return useQuery({
    queryKey: ["accounting", "attachments", type, id],
    enabled: id != null,
    queryFn: async (): Promise<Attachment[]> => {
      const { data } = await apiClient.get("/accounting/attachments", {
        params: { attachable_type: type, attachable_id: id },
      });
      return data.data as Attachment[];
    },
  });
}

export function useUploadAttachment(type: Attachable, id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (file: File): Promise<Attachment> => {
      const form = new FormData();
      form.append("file", file);
      form.append("attachable_type", type);
      form.append("attachable_id", String(id));
      const { data } = await apiClient.post("/accounting/attachments", form);
      return data.data as Attachment;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["accounting", "attachments", type, id] });
    },
  });
}

/** Fetches the file WITH the auth token (private endpoint) and triggers a download. */
export async function downloadAttachment(att: Attachment): Promise<void> {
  const res = await apiClient.get(`/accounting/attachments/${att.id}/download`, {
    responseType: "blob",
  });
  const url = URL.createObjectURL(res.data as Blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = att.original_name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}
