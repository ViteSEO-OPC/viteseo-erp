import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";
import type { Contact } from "@/modules/accounting/types";

export function useContacts(type?: "customer" | "vendor") {
  return useQuery({
    queryKey: ["accounting", "contacts", type ?? "all"],
    queryFn: async (): Promise<Contact[]> => {
      const { data } = await apiClient.get("/accounting/contacts", {
        params: type ? { type } : {},
      });
      return data.data as Contact[];
    },
  });
}

export interface NewContact {
  name: string;
  type: string;
  email?: string;
  phone?: string;
  tin?: string;
  address?: string;
}

export function useCreateContact() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: NewContact): Promise<Contact> => {
      const { data } = await apiClient.post("/accounting/contacts", payload);
      return data.data as Contact;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["accounting", "contacts"] }),
  });
}
