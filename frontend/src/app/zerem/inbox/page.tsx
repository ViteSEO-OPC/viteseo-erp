import { InboxPage } from "@/modules/crm/features/inbox/components/inbox-page";

/**
 * /zerem/inbox — the Conversation Inbox route.
 *
 * Thin by design: the CRM's own shell and RoleGuard already wrap everything under /zerem, and the
 * backend gates the data on `crm.conversations.view` regardless of what the client renders.
 */
export default function ZeremInboxPage() {
    return <InboxPage />;
}
