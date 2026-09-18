"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import {
  mapProviderFor,
  type MapProvider,
} from "@/modules/crm/features/nearby-clients/lib/map-provider";
import {
  canRunDiscovery,
  canRunNationwide,
  discoveryUnavailableReason,
} from "@/modules/crm/features/nearby-clients/lib/discovery-capability";
import { canRunAiDiscovery } from "@/modules/crm/features/nearby-clients/lib/ai-discovery";

/**
 * Which discovery provider is actually running, as the Nearby board needs to know it.
 *
 * READS THE EXISTING ENDPOINT, DELIBERATELY. `GET /discovery/config` already answers with the
 * EFFECTIVE provider and why — the same answer the scraper acts on — and it is authorized by the
 * `import` ability, which Business Developers hold. So the board learns the provider from the one
 * place that already decides it, instead of gaining a second provider-state system that could
 * disagree with the first. The admin-only settings endpoint would have been the wrong source: it is
 * restricted to platform super admins, and Nearby is a BD surface.
 *
 * IT CARRIES NO CREDENTIAL. The payload is provider identifiers and a reason code — by design, so
 * that showing it to every BD discloses nothing.
 */
export type DiscoveryConfig = {
  effectiveProvider: string;
  configuredProvider: string;
  reason: string;
  mode: string;
  isFallback: boolean;
  /**
   * Whether a run may ask AI to plan its searches — a status word, nothing else. Optional so a
   * backend older than AI Discovery reads as "not available" rather than failing to parse.
   */
  aiStrategy?: { status: string };
};

export type MapProviderState = {
  /** Which map implementation to render. Always defined — OpenStreetMap until proven otherwise. */
  provider: MapProvider;
  /** The effective discovery provider, when known. */
  effectiveProvider: string | null;
  /** True while the lookup is still in flight; the OSM map renders meanwhile. */
  isLoading: boolean;
};

export function useMapProvider(): MapProviderState {
  const query = useQuery<DiscoveryConfig>({
    queryKey: queryKeys.nearbyClients.discoveryConfig(),
    queryFn: () => apiClient.get("/discovery/config").then((r) => r.data.data as DiscoveryConfig),
    // The provider changes when an admin changes it, not while a BD reads the board — so this is
    // cached rather than re-fetched, and a stale answer costs at most one map style until reload.
    staleTime: 5 * 60_000,
    // NEVER a blocking failure. A board that refused to draw because a configuration lookup 403'd or
    // timed out would be worse than one drawn with the default map, which is what the data is
    // overwhelmingly likely to be anyway.
    retry: false,
  });

  return {
    provider: mapProviderFor(query.data?.effectiveProvider),
    effectiveProvider: query.data?.effectiveProvider ?? null,
    isLoading: query.isLoading,
  };
}

/**
 * Which on-demand discovery controls this workspace may show.
 *
 * SHARES THE MAP'S QUERY, not just its endpoint: the same cache key means the board asks
 * `GET /discovery/config` once and both the map style and the discovery controls read that one
 * answer. Two lookups could disagree, and a board that draws a Google map while hiding Google
 * discovery would be reporting two different truths about the same setting.
 */
export type DiscoveryCapability = {
  /** May any on-demand discovery UI be shown? False on the OpenStreetMap baseline. */
  canDiscover: boolean;
  /** May the Nationwide Philippines mode be offered? Google Places only, matching the server. */
  canRunNationwide: boolean;
  /** Why it is unavailable, for an operator — null when it is available. Never names a credential. */
  unavailableReason: string | null;
  /** May "Discover with AI" be offered? Google Places live AND the backend reports AI available. */
  canRunAi: boolean;
  /** The backend's AI status word (`available`, `insufficient_credit`, ...), or null while unknown. */
  aiStatus: string | null;
  /** True while the lookup is in flight. Capability is withheld until it answers. */
  isLoading: boolean;
};

export function useDiscoveryCapability(): DiscoveryCapability {
  const query = useQuery<DiscoveryConfig>({
    queryKey: queryKeys.nearbyClients.discoveryConfig(),
    queryFn: () => apiClient.get("/discovery/config").then((r) => r.data.data as DiscoveryConfig),
    staleTime: 5 * 60_000,
    retry: false,
  });

  const effective = query.data?.effectiveProvider ?? null;

  return {
    canDiscover: canRunDiscovery(effective),
    canRunNationwide: canRunNationwide(effective),
    unavailableReason: discoveryUnavailableReason(effective),
    canRunAi: canRunAiDiscovery(effective, query.data?.aiStrategy?.status),
    aiStatus: query.data?.aiStrategy?.status ?? null,
    isLoading: query.isLoading,
  };
}
