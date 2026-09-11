"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";

/**
 * What calling this deployment can actually do, for one lead.
 *
 * The Call panel renders from this and nothing else. That is the point: a control only appears when
 * the capability behind it exists, so the UI can never offer a browser call in a workspace with no
 * telephony provider, or a dial action for a lead whose "phone" is the text "N/A".
 *
 * Both false-ish answers are ORDINARY states of a fully working CRM, not errors:
 *   browserCalling false → no paid provider configured. The free manual workflow is what runs, and
 *                          it is complete on its own — the BD dials on their phone and logs the call.
 *   phone.dialable false → this lead's stored number cannot be safely turned into something to dial.
 *                          `phone.reason` says what to fix, in words written for the BD.
 */
export type CallCapability = {
  /** True only when a paid, browser-capable provider is configured AND usable right now. */
  browserCalling: boolean;
  /** The active transport's name — `manual` unless a provider is configured. */
  provider: string;
  phone: {
    /** The lead's number exactly as stored, shown verbatim so the BD can read or type it. */
    raw: string | null;
    /** Normalized for `tel:` and for a carrier, or null when it could not be derived safely. */
    e164: string | null;
    dialable: boolean;
    /** Why not, phrased for the BD. Null when the number is fine. */
    reason: string | null;
  };
};

export function useCallCapability(leadId: string) {
  const query = useQuery({
    queryKey: queryKeys.leads.callingCapability(leadId),
    queryFn: () =>
      apiClient
        .get(`/leads/${leadId}/calling-capability`)
        .then((r) => r.data.data as CallCapability),
    enabled: !!leadId,
    // Capability changes only when the deployment is reconfigured or the lead's phone is edited. The
    // latter invalidates by the detail(id) prefix, so polling for it would be waste.
    staleTime: 5 * 60 * 1000,
  });

  return { capability: query.data, isLoading: query.isLoading };
}
