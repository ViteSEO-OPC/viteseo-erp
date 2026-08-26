import { IntegrationsPage } from "@/modules/crm/features/settings/components/integrations-page";

// Landing target for the Google OAuth callback (`?gmail=connected` / `?gmail=error&reason=…`).
// The params are read server-side with Next's async searchParams — the same choice the Follow-ups
// deep link makes — so the page avoids a client `useSearchParams` CSR bailout. Only a status and a
// short reason code ever arrive here; the backend never puts a token or an authorization code in
// the redirect.
export default async function SettingsIntegrations({
  searchParams,
}: {
  searchParams: Promise<{ gmail?: string; reason?: string }>;
}) {
  const { gmail, reason } = await searchParams;

  return <IntegrationsPage gmailCallbackStatus={gmail} gmailCallbackReason={reason} />;
}
