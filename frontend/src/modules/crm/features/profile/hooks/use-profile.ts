"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { highestRole, type Role } from "@/modules/crm/lib/rbac";
import { useAuth } from "@/modules/crm/providers/auth-provider";

/** The signed-in user's own profile, as shown on the BD self-service Settings page. */
export type Profile = {
  name: string;
  email: string;
  phone: string | null;
  role: Role | null;
};

type ApiMe = {
  name?: string;
  email?: string;
  phone?: string | null;
  roles?: string[];
};

function mapProfile(data: ApiMe): Profile {
  return {
    name: data.name ?? "",
    email: data.email ?? "",
    phone: data.phone ?? null,
    role: highestRole(data.roles) ?? null,
  };
}

/** Authoritative current-user profile (GET /me) — includes `phone`, which the cached auth user
 *  (localStorage) does not carry. */
export function useProfile() {
  return useQuery({
    queryKey: queryKeys.me.profile(),
    queryFn: async (): Promise<Profile> => {
      const res = await apiClient.get("/me");
      return mapProfile(res.data.data as ApiMe);
    },
  });
}

/** Self-service update of the current user's own name + phone (PATCH /me). Email and role are
 *  read-only server-side; this hook never sends them. */
export function useUpdateProfile() {
  const queryClient = useQueryClient();
  const { updateUser } = useAuth();

  return useMutation({
    mutationFn: async (payload: {
      name: string;
      phone: string | null;
    }): Promise<Profile> => {
      const res = await apiClient.patch("/me", {
        name: payload.name,
        phone: payload.phone,
      });
      return mapProfile(res.data.data as ApiMe);
    },
    onSuccess: (profile) => {
      // Keep the query cache and the (non-react-query) auth context in sync so the header name /
      // avatar update immediately after a save.
      queryClient.setQueryData(queryKeys.me.profile(), profile);
      updateUser({ name: profile.name });
      toast.success("Profile updated");
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to update profile"));
    },
  });
}
