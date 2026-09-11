"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import {
  CALLING_SETTINGS_ENDPOINT,
  fieldLabel,
  isCentrallyManagedRefusal,
  missingByOwner,
  testStatusLabel,
} from "@/modules/crm/features/settings/lib/calling-settings";

/**
 * Settings → Integrations → Calling.
 *
 * The API deliberately returns CONFIGURATION METADATA ONLY. There is no Auth Token and no API Secret
 * in any payload, and none is ever wanted here: the browser's job is to say whether a secret exists
 * and to replace or remove it, never to hold one. `hasAuthToken` / `authTokenLast4` are the whole of
 * what a client learns about each — not its length, not its prefix, not its ciphertext. Same
 * discipline as the Gmail and Nearby integrations.
 *
 * The IDENTIFIERS do come back in full (`accountSid`, `apiKey`, `twimlAppSid`, `callerId`). They are
 * values Twilio itself shows in its console, none of them can place a call on its own, and an admin has
 * to be able to see them to spot a paste error. `webhookBaseUrl` is the DEPLOYMENT's public origin —
 * where Twilio calls back — reported for reference; no organization can change it.
 *
 * The one shape worth understanding before reading the card: the API reports the STORED settings and
 * what is actually IN FORCE separately, because they can differ. A saved-but-switched-off account or a
 * configuration missing one field both produce a "browser calling is off" that the stored row alone
 * would not explain.
 *
 * The organization is never named: every request goes to one fixed endpoint and the server resolves
 * the organization from the signed-in user.
 */

/** Outcome of the last explicit connection test. `null` means never tested. */
export type CallingTestStatus =
  | "ok"
  | "auth_failed"
  | "api_key_failed"
  | "twiml_app_missing"
  | "rate_limited"
  | "unreachable"
  | "not_configured";

/** Where the configuration in force came from. */
export type CallingConfigSource = "organization" | "deployment" | "none";

/**
 * The fields the server requires before browser calling can run. `webhook_base_url` is on the list
 * because the server needs it too — but it is the deployment's, so only ViteSEO can supply it.
 */
export type CallingField =
  | "account_sid"
  | "auth_token"
  | "api_key"
  | "api_secret"
  | "twiml_app_sid"
  | "caller_id"
  | "webhook_base_url";

export type CallProviderSettings = {
  provider: string;
  enabled: boolean;

  // -- identifiers: safe to display --
  accountSid: string | null;
  apiKey: string | null;
  twimlAppSid: string | null;
  callerId: string | null;
  /** The deployment's public origin Twilio calls back on. Read-only; never an organization setting. */
  webhookBaseUrl: string | null;

  // -- secret PRESENCE only. The secrets themselves are never sent. --
  hasAuthToken: boolean;
  authTokenLast4: string | null;
  hasApiSecret: boolean;
  apiSecretLast4: string | null;

  lastTestStatus: CallingTestStatus | null;
  lastTestedAt: string | null;
  /** Whether this org has ever configured the provider — distinct from having enabled it. */
  configured: boolean;
  updatedAt: string | null;
};

export type CallingIntegration = {
  /** What will actually happen on the next call. The only field the Call panel's behaviour follows. */
  browserCalling: boolean;
  provider: string;
  source: CallingConfigSource;
  /**
   * This organization's calling runs on ViteSEO's centralized Twilio account, configured on the
   * server. The card then shows status only — no credential form, no switch, no test — and the API
   * refuses every write.
   */
  centrallyManaged: boolean;
  enabled: boolean;
  complete: boolean;
  /**
   * Whether Twilio ACCEPTED these credentials on an explicit connection test. The third condition of
   * browser calling, alongside `enabled` and `complete` — well-formed strings are not evidence that an
   * account exists, so an untested configuration never offers a BD a button that can only fail at the
   * carrier.
   */
  verified: boolean;
  /** Which required fields are still empty. Field keys only — never values. */
  missing: CallingField[];
  requiredFields: CallingField[];
  settings: CallProviderSettings;
};

/**
 * What the admin sends — exactly the fields the API accepts. Both secrets are optional: omitting one
 * keeps the stored value. There is no callback URL and no organization: neither is the admin's to set.
 */
export type CallingConfigInput = Partial<{
  enabled: boolean;
  account_sid: string;
  api_key: string;
  twiml_app_sid: string;
  caller_id: string;
  auth_token: string;
  api_secret: string;
}>;

export function useCallingIntegration() {
  const query = useQuery({
    queryKey: queryKeys.integrations.calling(),
    queryFn: async (): Promise<CallingIntegration> => {
      const res = await apiClient.get(CALLING_SETTINGS_ENDPOINT);
      return res.data.data as CallingIntegration;
    },
  });

  // 403 is an answer, not a failure: the endpoint is Admin / Super Admin only, and rendering
  // "could not load" for a role the server is correctly refusing turns a deliberate boundary into
  // what looks like a broken screen. Same handling as the Nearby card.
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
 * refetched. That matters for one case in particular: a `not_configured` test verdict is deliberately
 * not persisted server-side when no row exists, so a refetch would discard it and the admin would see
 * nothing happen.
 *
 * It also invalidates the Call panel's capability query, because turning browser calling on or off is
 * exactly the thing that changes which controls a BD sees.
 *
 * A CENTRALLY-MANAGED REFUSAL (403) re-reads the configuration: the organization is managed by
 * ViteSEO, so the card must stop offering a form and show the read-only status the server now reports.
 * The toast carries the server's own sentence, which is a fixed message, never a secret.
 */
function useOverviewMutation<TArgs>(
  mutationFn: (args: TArgs) => Promise<CallingIntegration>,
  onDone?: (data: CallingIntegration, args: TArgs) => void,
  fallbackError = "Could not update the calling configuration",
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: (data, args) => {
      queryClient.setQueryData(queryKeys.integrations.calling(), data);
      // The capability answer is per lead and cached under each lead's detail key, so this
      // invalidates by the shared prefix rather than trying to name every lead the admin has open.
      void queryClient.invalidateQueries({ queryKey: queryKeys.leads.all });
      onDone?.(data, args);
    },
    onError: (err) => {
      if (isCentrallyManagedRefusal(err)) {
        void queryClient.invalidateQueries({ queryKey: queryKeys.integrations.calling() });
      }
      toast.error(getApiErrorMessage(err, fallbackError));
    },
  });
}

/** Save configuration. Secrets are write-only: omit one to keep what is stored. */
export function useConfigureCalling() {
  return useOverviewMutation<CallingConfigInput>(
    async (body) => {
      const res = await apiClient.put(CALLING_SETTINGS_ENDPOINT, body);
      return res.data.data as CallingIntegration;
    },
    (data) => {
      if (data.browserCalling) {
        toast.success("Calling configuration saved");
        return;
      }

      if (!data.complete) {
        const { client, server } = missingByOwner(data.missing);
        toast.warning(
          "Saved, but browser calling is not running yet",
          client.length > 0
            ? `Still needed: ${client.map(fieldLabel).join(", ")}.`
            : server.length > 0
              ? "ViteSEO still needs to set this server's public callback address."
              : undefined,
        );
        return;
      }

      if (!data.verified) {
        // The most common path after an edit: a credential changed, which cleared the previous
        // verdict. Saying so is what stops an admin thinking the save failed.
        toast.warning(
          "Saved — run Test connection to finish",
          "Browser calling starts once Twilio confirms these credentials.",
        );
        return;
      }

      toast.success("Calling configuration saved");
    },
  );
}

/** Remove the stored Auth Token and API Secret. Identifiers and the switch are left alone. */
export function useClearCallingCredentials() {
  return useOverviewMutation<void>(
    async () => {
      const res = await apiClient.delete(`${CALLING_SETTINGS_ENDPOINT}/credentials`);
      return res.data.data as CallingIntegration;
    },
    () => toast.success("Twilio credentials removed"),
    "Could not remove the credentials",
  );
}

/**
 * Verify the organization's own stored configuration.
 *
 * The toast reports the server's verdict verbatim — including `not_configured`, which makes no
 * outbound request at all. Reporting a success the server did not give is the one thing this must
 * never do. No call is placed: the check is read-only against Twilio's REST API.
 */
export function useTestCallingConnection() {
  return useOverviewMutation<void>(
    async () => {
      const res = await apiClient.post(`${CALLING_SETTINGS_ENDPOINT}/test`);
      return res.data.data as CallingIntegration;
    },
    (data) => {
      const status = data.settings.lastTestStatus;
      if (status === "ok") {
        toast.success(
          "Connection successful",
          data.browserCalling
            ? "Twilio accepted these credentials. Browser calling is now available to your team."
            : "Twilio accepted these credentials. Switch browser calling on to make it available.",
        );
      } else {
        toast.warning("Connection not verified", testStatusLabel(status));
      }
    },
    "Could not run the connection test",
  );
}
