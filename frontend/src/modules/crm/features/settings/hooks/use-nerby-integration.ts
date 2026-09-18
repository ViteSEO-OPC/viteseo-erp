"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";

/**
 * Settings → Integrations → Nearby Lead Generation.
 *
 * The API deliberately returns CONFIGURATION METADATA ONLY. There is no API key in any payload and
 * none is ever wanted here: the browser's job is to say whether a credential exists and to replace or
 * remove it, never to hold one. `hasApiKey` and `apiKeyLast4` are the whole of what a client learns —
 * not the key's length, not its prefix, not its ciphertext. Same discipline as the Gmail integration.
 *
 * The one shape worth understanding before reading the card: the API reports the CONFIGURED provider
 * and the EFFECTIVE provider separately, because they can differ. Selecting OpenAI without a usable
 * key falls back to OpenStreetMap, and the UI must never let that read as active OpenAI execution.
 */

/** Outcome of the last explicit connection test. `null` means never tested. */
export type NerbyTestStatus =
  | "ok"
  | "auth_failed"
  // The credential is genuine but the account behind it cannot pay. Distinct from `auth_failed`
  // (the key is fine) and from `unreachable` (nothing is down) because it is the only one an
  // operator fixes with a payment rather than a configuration change.
  | "insufficient_credit"
  | "rate_limited"
  | "unreachable"
  | "not_configured"
  | "not_applicable";

/** Why the effective provider is what it is. `configured` means no fallback occurred. */
export type NerbyFallbackReason =
  | "default_no_configuration"
  | "configured"
  | "provider_disabled"
  | "provider_not_configured"
  | "unknown_provider";

export type NerbyProviderConfig = {
  provider: string;
  label: string;
  /** Whether this provider needs a key before it can run — drives which controls to render. */
  requiresCredentials: boolean;
  /** Provider-specific option names (e.g. `["model"]`), so a new provider needs no UI change. */
  optionKeys: string[];
  isActive: boolean;
  enabled: boolean;
  options: Record<string, string | null>;
  /** Presence only. The key itself is never sent. */
  hasApiKey: boolean;
  apiKeyLast4: string | null;
  lastTestStatus: NerbyTestStatus | null;
  lastTestedAt: string | null;
  /** Whether this provider has ever been configured — distinct from being enabled. */
  configured: boolean;
  updatedAt: string | null;
};

export type NerbyIntegration = {
  /** What an admin chose. */
  activeProvider: string;
  /** What will actually run. Differs from `activeProvider` whenever `isFallback` is true. */
  effectiveProvider: string;
  effectiveProviderLabel: string;
  reason: NerbyFallbackReason;
  /** `live` when the effective provider can genuinely run; `preview` when selected but not operable. */
  mode: "live" | "preview";
  isFallback: boolean;
  defaultProvider: string;
  providers: NerbyProviderConfig[];
};

const ENDPOINT = "/settings/integrations/nerby";

export function useNerbyIntegration() {
  const query = useQuery({
    queryKey: queryKeys.integrations.nerby(),
    queryFn: async (): Promise<NerbyIntegration> => {
      const res = await apiClient.get(ENDPOINT);
      return res.data.data as NerbyIntegration;
    },
  });

  // 403 is not a failure here, it is the answer. The provider API key is bought and
  // paid for by the platform, so these routes are super-admin only (see the comment
  // above them in the CRM route file) — a client's own CRM admin is refused on
  // purpose. Surfacing that as "could not load" turns a deliberate boundary into
  // what looks like a broken screen.
  const status = (query.error as { response?: { status?: number } } | null)?.response?.status;

  return {
    integration: query.data,
    isLoading: query.isLoading,
    isError: query.isError && status !== 403,
    isForbidden: status === 403,
    retry: query.refetch,
  };
}

/**
 * Every mutation returns the WHOLE overview, so the cache is seeded from the response rather than
 * refetched. That matters for one case in particular: a `not_configured` / `not_applicable` test
 * verdict is deliberately not persisted server-side (clicking Test must not create a row), so a
 * refetch would discard it and the admin would see nothing happen.
 */
function useOverviewMutation<TArgs>(
  mutationFn: (args: TArgs) => Promise<NerbyIntegration>,
  onDone?: (data: NerbyIntegration, args: TArgs) => void,
  fallbackError = "Could not update the Nearby configuration",
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: (data, args) => {
      queryClient.setQueryData(queryKeys.integrations.nerby(), data);
      onDone?.(data, args);
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, fallbackError));
    },
  });
}

/**
 * Choose the provider Nearby uses.
 *
 * Selection does NOT enable the provider and does not touch any credential — the server keeps those
 * separate deliberately, so choosing an engine can never be the same click as authorising it to spend
 * money. The toast reflects what actually happened, which may be a fallback.
 */
export function useSelectNerbyProvider() {
  return useOverviewMutation<string>(
    async (provider) => {
      const res = await apiClient.put(`${ENDPOINT}/provider`, { provider });
      return res.data.data as NerbyIntegration;
    },
    (data) => {
      if (data.isFallback) {
        toast.warning(
          "Provider selected, but not running",
          `${data.effectiveProviderLabel} is still handling discovery — finish configuring the selected provider.`,
        );
      } else {
        toast.success(`${data.effectiveProviderLabel} is now the discovery provider`);
      }
    },
    "Could not change the discovery provider",
  );
}

/** Configure one provider: enable/disable, replace the key, set provider-specific options. */
export function useConfigureNerbyProvider() {
  return useOverviewMutation<{
    provider: string;
    enabled?: boolean;
    api_key?: string;
    options?: Record<string, string>;
  }>(
    async ({ provider, ...body }) => {
      const res = await apiClient.put(`${ENDPOINT}/providers/${provider}`, body);
      return res.data.data as NerbyIntegration;
    },
    () => toast.success("Configuration saved"),
  );
}

/** Remove one provider's stored credential. Leaves selection and enablement alone. */
export function useClearNerbyCredential() {
  return useOverviewMutation<string>(
    async (provider) => {
      const res = await apiClient.delete(`${ENDPOINT}/providers/${provider}/credential`);
      return res.data.data as NerbyIntegration;
    },
    () => toast.success("API key removed"),
    "Could not remove the API key",
  );
}

/**
 * Verify a provider's stored credential.
 *
 * The toast reports the server's verdict verbatim — including the two outcomes that make no outbound
 * call at all (`not_configured`, `not_applicable`). Reporting a success the server did not give is
 * the one thing this must never do.
 */
export function useTestNerbyProvider() {
  return useOverviewMutation<string>(
    async (provider) => {
      const res = await apiClient.post(`${ENDPOINT}/providers/${provider}/test`);
      return res.data.data as NerbyIntegration;
    },
    (data, provider) => {
      const status = data.providers.find((p) => p.provider === provider)?.lastTestStatus ?? null;
      if (status === "ok") {
        toast.success("Connection successful");
      } else {
        toast.warning("Connection not verified", testStatusLabel(status));
      }
    },
    "Could not run the connection test",
  );
}

/**
 * Plain-language reading of a test verdict. Kept beside the type so a new server status cannot be
 * rendered as a raw code — the fallthrough says "not tested" rather than leaking an identifier.
 */
export function testStatusLabel(status: NerbyTestStatus | null): string {
  switch (status) {
    case "ok":
      return "Connected";
    case "auth_failed":
      return "The API key was rejected";
    case "insufficient_credit":
      return "The account has no credit remaining — add credit to continue";
    case "rate_limited":
      return "Rate limited — try again shortly";
    case "unreachable":
      return "Could not reach the provider";
    case "not_configured":
      return "No API key configured";
    case "not_applicable":
      return "No API key needed";
    default:
      return "Not tested";
  }
}
