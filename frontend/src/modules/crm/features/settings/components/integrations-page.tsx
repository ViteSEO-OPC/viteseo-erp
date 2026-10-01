"use client";

import { CopilotAiCard } from "@/modules/crm/features/settings/components/copilot-ai-card";
import { GmailIntegrationPage } from "@/modules/crm/features/settings/components/gmail-integration-page";
import { NerbyIntegrationCard } from "@/modules/crm/features/settings/components/nerby-integration-card";
import { TwilioCallingCard } from "@/modules/crm/features/settings/components/twilio-calling-card";

/**
 * Settings → Integrations. The page that owns the heading; each integration owns its own card.
 *
 * The header lived on the Gmail component while Gmail was the only integration, which was fine right
 * up until a second one arrived — a card sitting under a heading owned by an unrelated component is
 * the kind of arrangement that quietly gets worse with each addition. It moved here instead, and
 * `GmailIntegrationPage` was otherwise left untouched: same markup, same logic, same styling, minus
 * its `<header>`.
 *
 * Reached only by Admin / Super Admin. `/zerem/settings` is `minRole: crm_admin` in PAGE_ACCESS and
 * the RoleGuard matches by prefix, so this route inherits the gate without needing its own entry —
 * and every endpoint behind both cards is role-restricted server-side regardless.
 */
export function IntegrationsPage({
  gmailCallbackStatus,
  gmailCallbackReason,
}: {
  gmailCallbackStatus?: string;
  gmailCallbackReason?: string;
}) {
  return (
    <div className="flex flex-col gap-6">
      <header>
        <h2 className="text-2xl font-bold text-foreground">Integrations</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Connect the services the CRM reads from. Only Admins can change these.
        </p>
      </header>

      <GmailIntegrationPage
        callbackStatus={gmailCallbackStatus}
        callbackReason={gmailCallbackReason}
      />

      <NerbyIntegrationCard />

      <CopilotAiCard />

      <TwilioCallingCard />
    </div>
  );
}
