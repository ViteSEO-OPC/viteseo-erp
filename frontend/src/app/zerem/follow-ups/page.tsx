import { FollowUpsWorkspace } from "@/modules/crm/features/follow-ups/components/follow-ups-workspace";

// Deep-link target for Lead Detail's "Draft & send email" (/follow-ups?lead=<id>). Reading the
// param server-side (Next's async searchParams) hands the workspace an initial lead to focus,
// avoiding a client `useSearchParams` CSR bailout. Outreach stays the single owner of composing.
export default async function FollowUpsPage({
  searchParams,
}: {
  searchParams: Promise<{ lead?: string }>;
}) {
  const { lead } = await searchParams;
  return <FollowUpsWorkspace leadId={lead} />;
}
