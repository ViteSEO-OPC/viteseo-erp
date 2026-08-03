"use client";

import { useState } from "react";
import { AlertTriangle, CheckCircle2, Mail } from "lucide-react";
import { Card } from "@/modules/crm/components/ui/card";
import { Button } from "@/modules/crm/components/ui/button";
import { Badge } from "@/modules/crm/components/ui/badge";
import { Skeleton } from "@/modules/crm/components/ui/skeleton";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import {
  useGmailIntegration,
  useConnectGmail,
  useDisconnectGmail,
  type GmailConnectionStatus,
} from "@/modules/crm/features/settings/hooks/use-gmail-integration";

const cardClass = "border border-border bg-card p-6 shadow-card";

/**
 * Settings → Integrations. The whole surface for connecting the company mailbox to Google.
 *
 * Reached only by Admin / Super Admin: `/zerem/settings` is `minRole: crm_admin` in PAGE_ACCESS and
 * the RoleGuard matches by prefix, so this route inherits the gate — and the three API endpoints
 * behind it are `role:super_admin|crm_admin` regardless of what the client believes.
 *
 * Shows the mailbox and one of three states, and NOTHING about the credential itself. The status
 * payload carries no token, no expiry and no ciphertext, so there is nothing here that could leak
 * one; the connection is described in terms of what an admin can do about it, not what is stored.
 */
export function GmailIntegrationPage({
  callbackStatus,
  callbackReason,
}: {
  callbackStatus?: string;
  callbackReason?: string;
}) {
  const { integration, isLoading, isError, retry } = useGmailIntegration();
  const connect = useConnectGmail();
  const disconnect = useDisconnectGmail();
  const [confirmOpen, setConfirmOpen] = useState(false);

  return (
    <div className="flex flex-col gap-6">
      <header>
        <h2 className="text-2xl font-bold text-foreground">Integrations</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Connect the services the CRM reads from. Only Admins can change these.
        </p>
      </header>

      {/* The banner the OAuth callback lands on. `?gmail=…` is the only thing the redirect
          carries — a status and, on failure, a short reason code. Never a token or a code. */}
      {callbackStatus === "connected" ? (
        <CallbackBanner
          tone="success"
          message="Gmail connected. The CRM can now read this mailbox."
        />
      ) : null}
      {callbackStatus === "error" ? (
        <CallbackBanner tone="error" message={reasonMessage(callbackReason)} />
      ) : null}

      <Card className={cardClass}>
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="flex items-start gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted">
              <Mail className="size-5 text-secondary-foreground" aria-hidden />
            </span>
            <div>
              <h3 className="text-base font-bold text-card-foreground">Gmail</h3>
              <p className="text-sm text-muted-foreground">
                Reads replies for the shared mailbox so they appear on the lead timeline.
              </p>

              {isLoading ? (
                <Skeleton className="mt-3 h-5 w-64" />
              ) : integration ? (
                <div className="mt-3 flex flex-wrap items-center gap-2">
                  <span className="text-sm font-semibold text-card-foreground">
                    {integration.mailbox || "No mailbox configured"}
                  </span>
                  <StatusChip status={integration.status} />
                </div>
              ) : null}
            </div>
          </div>

          {integration ? (
            <div className="flex shrink-0 gap-2">
              {integration.status === "connected" ? (
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setConfirmOpen(true)}
                  disabled={disconnect.isPending}
                >
                  Disconnect
                </Button>
              ) : (
                <Button
                  variant="default"
                  size="sm"
                  onClick={() => connect.mutate()}
                  disabled={connect.isPending || !integration.mailbox}
                >
                  {/* Reconnect, not Connect, when a grant exists but cannot renew itself —
                      the action is the same but the admin is fixing something, not starting. */}
                  {connect.isPending
                    ? "Opening Google…"
                    : integration.status === "reauthorization_required"
                      ? "Reconnect Gmail"
                      : "Connect Gmail"}
                </Button>
              )}
            </div>
          ) : null}
        </div>

        {isError ? (
          <div className="mt-4">
            <ErrorState
              message="Could not load the Gmail connection status."
              onRetry={() => void retry()}
            />
          </div>
        ) : null}

        {integration && !integration.mailbox ? (
          <p className="mt-4 border-t border-border-subtle pt-4 text-sm text-muted-foreground">
            No mailbox is configured for the CRM, so there is nothing to connect a Google account to.
          </p>
        ) : null}

        {integration?.status === "reauthorization_required" ? (
          <p className="mt-4 border-t border-border-subtle pt-4 text-sm text-muted-foreground">
            The stored authorization can no longer be renewed and will stop working. Reconnect the
            mailbox to restore access.
          </p>
        ) : null}
      </Card>

      {/* Disconnecting stops the CRM reading company mail, so it takes a deliberate second step. */}
      <ConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title="Disconnect Gmail?"
        description={`The CRM will stop reading ${integration?.mailbox ?? "this mailbox"} and new replies will no longer appear on lead timelines. You can reconnect at any time.`}
        confirmLabel="Disconnect"
        destructive
        loading={disconnect.isPending}
        onConfirm={() =>
          disconnect.mutate(undefined, { onSettled: () => setConfirmOpen(false) })
        }
      />
    </div>
  );
}

function StatusChip({ status }: { status: GmailConnectionStatus }) {
  if (status === "connected") {
    return (
      <Badge className="bg-success-subtle text-success-foreground">
        <CheckCircle2 className="size-3" aria-hidden /> Connected
      </Badge>
    );
  }
  if (status === "reauthorization_required") {
    return (
      <Badge variant="destructive">
        <AlertTriangle className="size-3" aria-hidden /> Reconnection required
      </Badge>
    );
  }
  return <Badge variant="outline">Not connected</Badge>;
}

function CallbackBanner({ tone, message }: { tone: "success" | "error"; message: string }) {
  const success = tone === "success";
  return (
    <div
      role="status"
      className={
        success
          ? "flex items-start gap-2 rounded-lg border border-success bg-success-subtle p-4"
          : "flex items-start gap-2 rounded-lg border border-danger bg-danger-subtle p-4"
      }
    >
      {success ? (
        <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-success" aria-hidden />
      ) : (
        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-danger" aria-hidden />
      )}
      <p className="text-sm font-medium text-foreground">{message}</p>
    </div>
  );
}

/**
 * Reason codes come from the backend's GmailConsentFailedException vocabulary and are the ONLY
 * failure detail the redirect carries. Mapped here rather than rendered raw so the user reads an
 * instruction, and so an unrecognised code degrades to a generic sentence instead of printing
 * whatever arrived in the URL.
 */
function reasonMessage(reason: string | undefined): string {
  switch (reason) {
    case "access_denied":
      return "Google access was declined. Nothing was connected.";
    case "invalid_state":
      return "That connection link had expired or was already used. Start again.";
    case "insufficient_scope":
      return "The Google account did not grant Gmail read access. Reconnect and leave every requested permission ticked.";
    case "mailbox_mismatch":
      return "That Google account is not the configured CRM mailbox. Sign in as the CRM mailbox and try again.";
    case "missing_refresh_token":
      return "Google did not return a renewable authorization. Remove this app at myaccount.google.com/permissions, then connect again.";
    case "exchange_failed":
    case "identity_unavailable":
      return "Google could not be reached to finish the connection. Try again in a moment.";
    case "mailbox_not_configured":
    case "oauth_not_configured":
      return "The Gmail integration is not fully configured on the server. Nothing was connected.";
    default:
      return "The Google connection did not complete. Nothing was connected.";
  }
}
