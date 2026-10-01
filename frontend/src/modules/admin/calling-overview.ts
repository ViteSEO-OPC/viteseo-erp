import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/core/api-client";
import type {
  CallingConfigSource,
  CallingField,
  CallingTestStatus,
} from "@/modules/crm/features/settings/hooks/use-calling-integration";

/**
 * GET /api/v1/admin/calling — how calling is configured across every organization.
 *
 * READ-ONLY, and there is no mutation in this module on purpose. A client's Twilio account is their
 * property and their bill: this answers "is their calling set up, and what is missing?" for support,
 * and every change stays with that organization's own admin on its own settings screen.
 *
 * The payload carries NO credential and no account identifier — not the Account SID, not the API Key
 * SID. They are not secrets and an organization's own card shows them freely, but a cross-tenant screen
 * is where "not a secret" stops being a good enough reason to show one tenant's values to another's
 * reader. Verdicts only; see CallingOverviewService on the server.
 *
 * Super admin only — enforced on the route, so a 403 here is the server working, not a bug.
 */

/** How this deployment routes calls, and whether that configuration is sound. */
export interface CallingMode {
  /** The transport CALL_PROVIDER selects: `manual` or `twilio`. */
  provider: string;
  /** Whether client organizations may bring their own Twilio account at all. */
  organizationCredentials: boolean;
  /** The organization carried on ViteSEO's centralized account, when per-organization mode is on. */
  centralOrgId: number | null;
  /** Whether the deployment's own TWILIO_* values are complete. Incomplete is not a fault. */
  centralAccountComplete: boolean;
  /**
   * Set when per-organization mode is on but names no real central organization. Everything below then
   * reads "manual calling" as a CONSEQUENCE of this rather than as a per-organization fault.
   */
  misconfiguration: string | null;
  misconfigurationMessage: string | null;
}

export interface CallingOrganization {
  id: number;
  name: string;
  /** What a BD in this organization gets on the next call. */
  browserCalling: boolean;
  /** Its calling runs on ViteSEO's centralized account and cannot be configured by its own admin. */
  centrallyManaged: boolean;
  source: CallingConfigSource;
  enabled: boolean;
  complete: boolean;
  verified: boolean;
  /** Required fields still empty. Field KEYS only — never values. */
  missing: CallingField[];
  /** Whether this organization has ever saved a configuration of its own. */
  configured: boolean;
  /** It has a stored row AND nothing reads it, because its calling is centrally managed. */
  storedRowIgnored: boolean;
  lastTestStatus: CallingTestStatus | null;
  lastTestedAt: string | null;
}

export interface CallingOverview {
  mode: CallingMode;
  organizations: CallingOrganization[];
}

export function useCallingOverview() {
  return useQuery({
    queryKey: ["admin", "calling"],
    queryFn: async (): Promise<CallingOverview> => {
      const { data } = await apiClient.get("/admin/calling");
      return data.data as CallingOverview;
    },
  });
}
