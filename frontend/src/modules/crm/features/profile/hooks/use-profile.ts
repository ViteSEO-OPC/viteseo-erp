"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { STALE } from "@/modules/crm/lib/query-freshness";
import { highestRole, type Role } from "@/modules/crm/lib/rbac";
import { useAuth } from "@/modules/crm/providers/auth-provider";

/** The signed-in user's own profile, as shown on the BD self-service Settings page. */
export type Profile = {
  name: string;
  email: string;
  phone: string | null;
  role: Role | null;
  /** Renderable photo link — short-lived and signed, so it is read fresh from every response and
   *  never persisted anywhere. Null means "show initials". */
  avatarUrl: string | null;
  /** Whether a photo is stored at all. Distinct from `avatarUrl`, which can be null because a link
   *  could not be issued — that difference is what decides whether to offer "Remove". */
  hasPhoto: boolean;
};

type ApiMe = {
  name?: string;
  email?: string;
  phone?: string | null;
  roles?: string[];
  avatar?: string | null;
  avatar_url?: string | null;
};

function mapProfile(data: ApiMe): Profile {
  return {
    name: data.name ?? "",
    email: data.email ?? "",
    phone: data.phone ?? null,
    role: highestRole(data.roles) ?? null,
    avatarUrl: data.avatar_url ?? null,
    hasPhoto: Boolean(data.avatar),
  };
}

/** Authoritative current-user profile (GET /me) — includes `phone`, which the cached auth user
 *  (localStorage) does not carry.
 *
 *  Shell-level: `useAvatarUrl` reads this for the topbar UserMenu, so it is mounted on every CRM
 *  screen — see {@link STALE}. The window is well inside `ProfilePhotoService::LINK_TTL_MINUTES`
 *  (30 min), so a cached `avatar_url` cannot outlive the signature it carries: any remount or
 *  window focus more than 30s after the last read re-signs it. */
export function useProfile() {
  return useQuery({
    queryKey: queryKeys.me.profile(),
    queryFn: async (): Promise<Profile> => {
      const res = await apiClient.get("/me");
      return mapProfile(res.data.data as ApiMe);
    },
    staleTime: STALE.standard,
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

/**
 * The signed-in user's own profile photo.
 *
 * Both mutations return the WHOLE profile, so the cache is seeded from the response rather than
 * refetched — the avatar link is signed and short-lived, and the freshest one is the one that just
 * came back. Seeding also means the Settings card updates on the same tick as the toast, with no
 * second round trip for the user to watch.
 *
 * The upload is a plain multipart POST; validation (PNG/JPG, 4 MB) is the server's answer, surfaced
 * verbatim rather than duplicated here, so the two can never disagree about what is acceptable.
 */
function usePhotoMutation<TArgs>(
  mutationFn: (args: TArgs) => Promise<Profile>,
  successMessage: string,
  fallbackError: string,
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: (profile) => {
      queryClient.setQueryData(queryKeys.me.profile(), profile);
      toast.success(successMessage);
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, fallbackError));
    },
  });
}

/** Upload/replace the photo (POST /me/photo). The previous file is discarded server-side. */
export function useUploadProfilePhoto() {
  return usePhotoMutation<File>(
    async (file) => {
      const form = new FormData();
      form.append("photo", file);

      const res = await apiClient.post("/me/photo", form, {
        // Let the browser set the multipart boundary; a hardcoded Content-Type would omit it.
        headers: { "Content-Type": "multipart/form-data" },
      });

      return mapProfile(res.data.data as ApiMe);
    },
    "Profile photo updated",
    "Failed to upload the photo",
  );
}

/** Remove the photo (DELETE /me/photo) — back to initials. Idempotent server-side. */
export function useRemoveProfilePhoto() {
  return usePhotoMutation<void>(
    async () => {
      const res = await apiClient.delete("/me/photo");
      return mapProfile(res.data.data as ApiMe);
    },
    "Profile photo removed",
    "Failed to remove the photo",
  );
}

/**
 * The signed-in user's avatar link, for any surface that only needs to DISPLAY it.
 *
 * Deliberately a read of the same `me.profile` query the Settings and Profile pages use, rather
 * than a second source of truth: TanStack dedupes by key, so the topbar, Settings and Profile all
 * observe ONE cache entry. That is what makes an upload or a removal reach every avatar on the
 * same tick — the mutations seed that entry, and every consumer re-renders from it.
 *
 * Not read from the auth context on purpose: the cached auth user is persisted to localStorage,
 * and a signed URL that expires in 30 minutes must never be written there.
 */
export function useAvatarUrl(): string | null {
  const { data } = useProfile();

  return data?.avatarUrl ?? null;
}
