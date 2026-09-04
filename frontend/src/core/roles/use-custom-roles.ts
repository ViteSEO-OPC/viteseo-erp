import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AxiosError } from "axios";

import { apiClient } from "@/core/api-client";

/**
 * The org's own role builder — GET /capabilities, and CRUD over /roles.
 *
 * PLATFORM-LEVEL, NOT MODULE-LEVEL. These endpoints carry no module prefix and
 * return one answer for the whole org, which is why the hooks live in core and
 * not under modules/hr | crm | projects: TeamOra, Zerem and Sprintly each give
 * their admin a door to the SAME roles, and a per-module copy of this file would
 * mean three caches of one list that disagree the moment one of them writes.
 */

/** One tickable capability, as the catalog returns it. */
export interface Capability {
  key: string;
  /** The server's wording, e.g. "Leave requests: approve". */
  label: string;
  /** The permission's subject ("leave_requests") — the sub-heading inside a feature. */
  group: string;
}

/**
 * A block of capabilities the org availed. `label` is the PLAN's wording for it
 * ("PH payroll (SSS/PhilHealth/Pag-IBIG/BIR)"), so the blocks read back as the
 * things the client paid for rather than as permission namespaces.
 */
export interface CapabilityFeature {
  feature: string;
  label: string;
  capabilities: Capability[];
}

export interface CustomRole {
  id: number;
  name: string;
  permissions: string[];
}

/** A built-in role and what it grants — read-only reference. */
/** Which system a built-in role belongs to: "hr", "crm" or "pms". */
export type RoleFeature = "hr" | "crm" | "pms";

export interface PresetRole {
  key: string;
  label: string;
  feature: RoleFeature;
  capabilities: { key: string; label: string }[];
}

export interface CustomRolePayload {
  name: string;
  permissions: string[];
}

const capabilitiesKey = ["platform", "capabilities"] as const;
/**
 * Every module's team list, as a prefix.
 *
 * A role write changes what the team screens can OFFER and, on an edit or a
 * delete, what the members on that role are reported as holding. Both halves
 * now sit on one page, so a stale list is visible immediately rather than a
 * navigation away.
 */
const teamAccountsKey = ["platform", "team-accounts"] as const;
const rolesKey = ["platform", "custom-roles"] as const;
const presetsKey = ["platform", "role-presets"] as const;

/** What this org may put on a role — already filtered to its plan by the server. */
export function useCapabilities() {
  return useQuery({
    queryKey: capabilitiesKey,
    queryFn: async (): Promise<CapabilityFeature[]> => {
      const { data } = await apiClient.get("/capabilities");
      return data.data as CapabilityFeature[];
    },
    // The catalog changes only when the subscription does, never mid-edit.
    staleTime: 60 * 60 * 1000,
  });
}

/** The org's own custom roles. */
export function useCustomRoles() {
  return useQuery({
    queryKey: rolesKey,
    queryFn: async (): Promise<CustomRole[]> => {
      const { data } = await apiClient.get("/roles");
      return data.data as CustomRole[];
    },
  });
}

/** The built-in roles with their capabilities (read-only reference). */
export function usePresetRoles() {
  return useQuery({
    queryKey: presetsKey,
    queryFn: async (): Promise<PresetRole[]> => {
      const { data } = await apiClient.get("/roles/presets");
      return data.data as PresetRole[];
    },
    staleTime: 60 * 60 * 1000,
  });
}

export function useCreateCustomRole() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: CustomRolePayload): Promise<CustomRole> => {
      const { data } = await apiClient.post("/roles", payload);
      return data.data as CustomRole;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: rolesKey }),
  });
}

export function useUpdateCustomRole() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, ...payload }: CustomRolePayload & { id: number }): Promise<CustomRole> => {
      const { data } = await apiClient.put(`/roles/${id}`, payload);
      return data.data as CustomRole;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: rolesKey });
      qc.invalidateQueries({ queryKey: teamAccountsKey });
    },
  });
}

export function useDeleteCustomRole() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number): Promise<void> => {
      await apiClient.delete(`/roles/${id}`);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: rolesKey });
      qc.invalidateQueries({ queryKey: teamAccountsKey });
    },
  });
}

/** Why a save was refused, split by the two things a 422 here can mean. */
export interface RoleWriteFailure {
  /**
   * The plan gate's refusal, in the server's own words. It is NOT a typo the
   * operator can correct in the form — it means the org never availed what was
   * ticked — so callers must show it as its own statement rather than folding it
   * into the field's validation text.
   */
  planRefusal: string | null;
  /** A name-field complaint (taken, too long) — this one IS correctable in place. */
  nameError: string | null;
  /** Whatever is worth saying when neither of the above applies. */
  message: string;
}

/**
 * Read a failed role write.
 *
 * The `permissions` key is treated as the plan refusal outright. The other
 * `permissions` rules the controller declares (required, array, min:1) are all
 * satisfied before the request leaves — the form's own schema refuses to submit
 * an empty tick list — so a `permissions` error arriving from the server is the
 * plan gate and nothing else. Either way the server's sentence is shown verbatim,
 * because it is the only place that names WHICH capability was refused.
 */
export function readRoleWriteFailure(error: unknown): RoleWriteFailure {
  const fallback = "Couldn’t save the role.";

  if (!(error instanceof AxiosError)) {
    return { planRefusal: null, nameError: null, message: fallback };
  }

  const body = error.response?.data as
    | { message?: string; errors?: Record<string, string[]> }
    | undefined;

  return {
    planRefusal: body?.errors?.permissions?.[0] ?? null,
    nameError: body?.errors?.name?.[0] ?? null,
    message: body?.message ?? fallback,
  };
}
