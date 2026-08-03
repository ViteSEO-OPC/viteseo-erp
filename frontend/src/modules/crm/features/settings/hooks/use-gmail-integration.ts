"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";

/**
 * Settings → Integrations → Gmail.
 *
 * The API deliberately returns METADATA ONLY — mailbox, provider, connected, status. There is no
 * token, expiry or ciphertext in any of these payloads and none is ever wanted here: the browser's
 * job is to say whether mail can be read and to start or end the connection, never to hold the
 * credential. Nothing in this file (or anywhere in the frontend) knows the Google client id or
 * secret either; the consent URL is minted server-side and handed over ready to visit.
 */

/**
 * `reauthorization_required` is a stored grant that cannot renew itself — it will stop working
 * within the hour and only a reconnect fixes it. It is NOT "the access token expired", which is the
 * ordinary state between refreshes and something the admin should never be shown.
 */
export type GmailConnectionStatus = "connected" | "not_connected" | "reauthorization_required";

export type GmailIntegration = {
  mailbox: string;
  provider: string;
  connected: boolean;
  status: GmailConnectionStatus;
};

const ENDPOINT = "/settings/integrations/gmail";

export function useGmailIntegration() {
  const query = useQuery({
    queryKey: queryKeys.integrations.gmail(),
    queryFn: async (): Promise<GmailIntegration> => {
      const res = await apiClient.get(ENDPOINT);
      return res.data.data as GmailIntegration;
    },
  });

  return {
    integration: query.data,
    isLoading: query.isLoading,
    isError: query.isError,
    retry: query.refetch,
  };
}

/**
 * Start the consent flow: ask the API for a URL, then send the WHOLE BROWSER to Google.
 *
 * A full top-level navigation, never an iframe or a popup-with-postMessage: Google refuses to
 * render its consent screen in a frame (X-Frame-Options), and users are entitled to see the real
 * accounts.google.com address bar before typing a password into it. The user comes back through the
 * server-side callback, which redirects to this page with a `?gmail=` status.
 *
 * No success toast — the navigation is the feedback, and any toast would be destroyed by it.
 */
export function useConnectGmail() {
  return useMutation({
    mutationFn: async (): Promise<string> => {
      const res = await apiClient.post(`${ENDPOINT}/authorization`);
      return res.data.data.authorization_url as string;
    },
    onSuccess: (authorizationUrl) => {
      window.location.assign(authorizationUrl);
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Could not start the Google connection"));
    },
  });
}

/**
 * Disconnect: the API revokes the grant at Google where it can and always drops the stored
 * credential. `revoked: false` means the revocation call did not get through — the CRM has stopped
 * using the mailbox either way, but the grant may still be live in the Google account and worth
 * removing by hand. Surfaced as a warning rather than swallowed, because only a human can finish it.
 */
export function useDisconnectGmail() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (): Promise<{ revoked: boolean }> => {
      const res = await apiClient.delete(ENDPOINT);
      return { revoked: Boolean(res.data.data.revoked) };
    },
    onSuccess: ({ revoked }) => {
      queryClient.invalidateQueries({ queryKey: queryKeys.integrations.gmail() });
      if (revoked) {
        toast.success("Gmail disconnected");
      } else {
        toast.warning(
          "Gmail disconnected here",
          "Google could not be reached to revoke access. Remove it at myaccount.google.com/permissions.",
        );
      }
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to disconnect Gmail"));
    },
  });
}
