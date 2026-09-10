import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AxiosError } from "axios";

import { apiClient } from "@/core/api-client";

/**
 * The org's staff accounts — GET/POST /<module>/team and PUT/DELETE on a member.
 *
 * ONE SCREEN, THREE ENDPOINTS. Unlike the role builder next door, these paths
 * ARE module-prefixed (/hr/team, /projects/team, /crm/team) and each one accepts
 * only its own two preset roles — so the module cannot be inferred here and is
 * carried as an argument. Everything else about the three is identical: the same
 * request bodies, the same response envelope, the same org-level seat cap (seats
 * are counted per ORG, not per module, so all three report the same numbers).
 * That is why the screen is written once in core and the module is a prop.
 *
 * The list is cached per module: the members are the same people, but each
 * endpoint reports the role IT knows about and nulls the rest, so one cache
 * shared across modules would show TeamOra's answer inside Sprintly.
 */

/** Which portal's team endpoint a screen is talking to. */
export type TeamModuleKey = "hr" | "projects" | "crm" | "accounting";

/** One access level a member can hold in a module. */
export interface TeamRolePreset {
  /** The Spatie role name the endpoint accepts, e.g. "hr_officer". */
  value: string;
  label: string;
  /** What the level actually lets the person do, for the picker. */
  description: string;
}

export interface TeamModule {
  key: TeamModuleKey;
  /** Product name, used in the screen's own wording. */
  product: string;
  /** Path under the API base. */
  path: string;
  /**
   * The module's presets, in the endpoint's `in:` order. Mirrors the PRESETS
   * const of that module's TeamController — send anything else and the server
   * refuses it, so this list is the picker AND the labels for the list rows.
   */
  roles: TeamRolePreset[];
}

/**
 * The three team endpoints, keyed by module.
 *
 * Zerem's Team & Roles page LISTS from here and writes through /users for the
 * account lifecycle. /users lists by Spatie role, so it returns nothing at all
 * for an org staffed on its own custom roles — a member holding one carries
 * direct permissions and no role. This endpoint counts org MEMBERSHIP, so it
 * sees everyone, and it both accepts and reports `custom:<id>`.
 */
export const TEAM_MODULES: Record<TeamModuleKey, TeamModule> = {
  hr: {
    key: "hr",
    product: "TeamOra",
    path: "/hr/team",
    roles: [
      {
        value: "hr_officer",
        label: "HR Officer",
        description: "Manages the whole HRIS — people, attendance, payroll, company setup.",
      },
      {
        value: "hr_employee",
        label: "Employee",
        description: "Self-service only — their own attendance, payslips, claims and requests.",
      },
    ],
  },
  projects: {
    key: "projects",
    product: "Sprintly",
    path: "/projects/team",
    roles: [
      {
        value: "pm_apm",
        label: "Admin / PM",
        description: "Runs the workspace — projects, groups, approvals and the team itself.",
      },
      {
        value: "pm_member",
        label: "Member",
        description: "Works on the projects they're assigned to.",
      },
    ],
  },
  crm: {
    key: "crm",
    product: "Zerem",
    path: "/crm/team",
    roles: [
      {
        value: "crm_admin",
        label: "CRM Manager",
        description: "Manages the pipeline, the accounts and everyone's leads.",
      },
      {
        value: "crm_bd",
        label: "Business Development",
        description: "Works their own leads and follow-ups.",
      },
    ],
  },
  accounting: {
    key: "accounting",
    // No product name of its own yet -- the other three are branded.
    product: "Accounting",
    // The platform path, not `/accounting/team`: this endpoint predates the
    // module's removal and lives in App\Http\Controllers, which is why it
    // survived it. Same contract as the other three.
    path: "/team",
    roles: [
      {
        value: "acc_admin",
        label: "Finance Admin",
        description: "Runs the books — accounts, entries, reversals, budgets and the team itself.",
      },
      {
        value: "acc_accountant",
        label: "Accountant",
        description: "Records and posts entries and payroll, but cannot rewrite the chart or reverse.",
      },
      {
        value: "acc_viewer",
        label: "Viewer / Auditor",
        description: "Reads everything, changes nothing.",
      },
    ],
  },
};

export interface TeamMember {
  id: number;
  name: string;
  email: string;
  /**
   * What the member holds HERE: one of this module's presets, `custom:<id>` for
   * one of the org's own roles, or null for neither. It is the `<select>` value
   * on the row, so it has to be the same string the picker's options carry —
   * which is why {@link customRoleValue} builds both ends of that pairing.
   */
  role: string | null;
  /** The custom role's name when `role` is a `custom:<id>`; null for a preset. */
  role_label: string | null;
  /**
   * The member's access to THIS module is suspended.
   *
   * Reported by every team endpoint but only acted on where the module owns a
   * suspension mechanism — today that is Zerem alone (crm_user_access), which is
   * why this arrived with `lead_count`: both exist so Zerem's member row can be
   * the merged one without a second list request.
   */
  suspended: boolean;
  /** Records currently assigned to them in this module — 0 when they hold none. */
  lead_count: number;
  /** The signed-in operator — the server refuses to re-role or remove them. */
  is_you: boolean;
}

/**
 * How a custom role is named on the wire.
 *
 * The three team endpoints take and report the org's own roles as `custom:<id>`,
 * never as a bare id — so the option a picker renders and the value a row reads
 * back must both come from here. Building either by hand is how a member ends up
 * on a role the select then shows as blank.
 */
export function customRoleValue(id: number): string {
  return `custom:${id}`;
}

/** Org-level seat usage. Both numbers count EVERY member of the org. */
export interface TeamSeats {
  used: number;
  limit: number;
}

export interface TeamAccounts {
  members: TeamMember[];
  seats: TeamSeats;
}

export interface NewTeamMember {
  name: string;
  email: string;
  role: string;
  /**
   * Sent as typed; the caller generates one when the operator leaves it blank.
   * Ignored outright when the address turns out to be a colleague's — a grant
   * must not reset the password they already sign in with.
   */
  password: string;
}

/**
 * What POST returns — the account that now holds the role, never its password.
 *
 * TWO SHAPES BEHIND ONE BUTTON, and `granted` is which one happened. The address
 * decides: one already belonging to a colleague of this org is GRANTED the role
 * on the sign-in they have (200, no account, no seat, `name`/`password` ignored),
 * a new one is CREATED as before (201). An address belonging to another org is
 * refused on `email` — a person belongs to one organisation.
 */
export interface CreatedTeamMember {
  id: number;
  name: string;
  email: string;
  role: string;
  /** True when an existing colleague was given access rather than signed up. */
  granted: boolean;
}

/**
 * The root of every module's cached member list.
 *
 * Exported because Zerem writes to accounts through /users while LISTING from
 * /crm/team: those mutations live outside core and would otherwise leave the row
 * they just changed showing its old state. Invalidating the root also refreshes
 * the other portals' seat counts, which are org-wide.
 */
export const TEAM_ACCOUNTS_QUERY_KEY = ["platform", "team-accounts"] as const;

const teamKey = (module: TeamModuleKey) => [...TEAM_ACCOUNTS_QUERY_KEY, module] as const;

/** The current org's members plus its seat usage, as this module sees them. */
export function useTeamAccounts(module: TeamModuleKey) {
  return useQuery({
    queryKey: teamKey(module),
    queryFn: async (): Promise<TeamAccounts> => {
      const { data } = await apiClient.get(TEAM_MODULES[module].path);
      return data.data as TeamAccounts;
    },
  });
}

/**
 * The name a built-in role goes by in the member pickers.
 *
 * The presets endpoint carries its own platform-wide labels, and for Sprintly
 * they disagree with these: "Project Manager" there, "Admin / PM" here. Both
 * appeared on the merged team page, one in the picker and one in the reference
 * directly under it, under a sentence saying they were the same group. The
 * picker's name wins, because that is the one the operator acts on.
 *
 * Preset keys are unique across modules, so one flat lookup is enough.
 */
export function presetRoleLabel(roleKey: string): string | undefined {
  for (const entry of Object.values(TEAM_MODULES)) {
    const match = entry.roles.find((role) => role.value === roleKey);
    if (match) return match.label;
  }
  return undefined;
}

export function useAddTeamMember(module: TeamModuleKey) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: NewTeamMember): Promise<CreatedTeamMember> => {
      const { data } = await apiClient.post(TEAM_MODULES[module].path, payload);
      return data.data as CreatedTeamMember;
    },
    // Every module's list moves: a created account takes an ORG seat, and a
    // granted one appears in THIS module's list having been absent from it. The
    // seat count the other portals show is stale either way.
    onSuccess: () => qc.invalidateQueries({ queryKey: TEAM_ACCOUNTS_QUERY_KEY }),
  });
}

export function useSetTeamMemberRole(module: TeamModuleKey) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, role }: { id: number; role: string }): Promise<void> => {
      await apiClient.put(`${TEAM_MODULES[module].path}/${id}`, { role });
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: teamKey(module) }),
  });
}

export function useRemoveTeamMember(module: TeamModuleKey) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number): Promise<void> => {
      await apiClient.delete(`${TEAM_MODULES[module].path}/${id}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: TEAM_ACCOUNTS_QUERY_KEY }),
  });
}

/** The fields the create form can put a message under. */
type TeamField = "name" | "email" | "password" | "role";

const TEAM_FIELDS: readonly TeamField[] = ["name", "email", "password", "role"];

/** Why a team write was refused, split by what the operator can do about it. */
export interface TeamWriteFailure {
  /**
   * The seat cap's refusal, in the server's own words. NOT a field the operator
   * can correct — the org has no room — so callers show it as its own statement
   * (and name the plan) rather than as a complaint about the form.
   */
  seatRefusal: string | null;
  /** Complaints that ARE correctable in place (email taken, name too long). */
  fieldErrors: Partial<Record<TeamField, string>>;
  /** Whatever is worth saying when neither of the above applies. */
  message: string;
}

/**
 * Read a failed team write.
 *
 * The `seat` key is the cap and nothing else — the controller raises it before
 * it validates anything, so it never arrives alongside field errors. Its
 * sentence is passed through verbatim because it is the only place that names
 * the org's actual limit.
 */
export function readTeamWriteFailure(error: unknown, fallback: string): TeamWriteFailure {
  if (!(error instanceof AxiosError)) {
    return { seatRefusal: null, fieldErrors: {}, message: fallback };
  }

  const body = error.response?.data as
    | { message?: string; errors?: Record<string, string[]> }
    | undefined;

  const fieldErrors: Partial<Record<TeamField, string>> = {};
  for (const field of TEAM_FIELDS) {
    const first = body?.errors?.[field]?.[0];
    if (first) fieldErrors[field] = first;
  }

  return {
    seatRefusal: body?.errors?.seat?.[0] ?? null,
    fieldErrors,
    message: body?.message ?? fallback,
  };
}
