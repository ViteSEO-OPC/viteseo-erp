"use client";

import { cn } from "@/modules/crm/lib/utils";
import { formatActivityTimestamp } from "@/modules/crm/lib/formatters";
import { useLeadEmails } from "@/modules/crm/features/leads/hooks/use-lead-emails";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import type { EmailThreadMessage } from "@/modules/crm/features/leads/types/lead-detail-types";

/** Human label for the delivery/lifecycle state stored on the email row. */
function deliveryLabel(status: string): string {
  return status.charAt(0).toUpperCase() + status.slice(1);
}

function DirectionBadge({ direction }: { direction: EmailThreadMessage["direction"] }) {
  const inbound = direction === "inbound";
  return (
    <span
      className={cn(
        "inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide",
        inbound
          ? "bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"
          : "bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300",
      )}
    >
      {inbound ? "Inbound" : "Outbound"}
    </span>
  );
}

function MessageCard({ message }: { message: EmailThreadMessage }) {
  const inbound = message.direction === "inbound";
  // Sender / recipient line: an inbound reply shows who wrote it; an outbound shows who it went to.
  const party = inbound
    ? `From ${message.fromName ?? message.fromEmail ?? "Unknown sender"}`
    : `To ${message.to ?? "—"}${message.senderName ? ` · sent by ${message.senderName}` : ""}`;

  return (
    <li
      className={cn(
        "rounded-lg border border-border bg-background p-4",
        // Direction accent rail so the two flows are distinguishable at a glance.
        inbound ? "border-l-2 border-l-teal-400" : "border-l-2 border-l-indigo-400",
      )}
    >
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <DirectionBadge direction={message.direction} />
          <span className="text-xs font-medium text-muted-foreground">
            {deliveryLabel(message.status)}
          </span>
        </div>
        <time className="shrink-0 text-xs text-muted-foreground">
          {formatActivityTimestamp(message.timestamp)}
        </time>
      </div>

      <p className="mt-2 truncate text-xs text-muted-foreground">{party}</p>
      <p className="mt-1 text-sm font-semibold text-card-foreground">
        {message.subject || "(no subject)"}
      </p>
      <p className="mt-1 whitespace-pre-wrap break-words text-sm text-muted-foreground">
        {message.body}
      </p>
    </li>
  );
}

type Props = {
  leadId: string;
};

/**
 * Read-only email conversation for a lead (outbound + inbound), chronologically ordered. A pure
 * presentation over the Email bounded context via `GET /leads/{lead}/emails` — no composition, no
 * business logic, no duplication of Planner/Activity/Outreach. Reply composer, attachments, AI
 * summaries, and search are future additions over this same panel (RC2 is read-only).
 */
export function EmailThread({ leadId }: Props) {
  const { data, hasMore, isLoading, isError } = useLeadEmails(leadId);

  return (
    <div className="rounded-xl border border-border bg-card p-5 shadow-card">
      <h3 className="mb-5 text-lg font-bold text-card-foreground">Email Conversation</h3>
      {isLoading ? (
        <div className="space-y-3">
          {[0, 1, 2].map((i) => (
            <div key={i} className="h-24 animate-pulse rounded-lg bg-muted" />
          ))}
        </div>
      ) : isError ? (
        <ErrorState message="Failed to load emails" />
      ) : !data || data.length === 0 ? (
        <EmptyState message="No emails yet" />
      ) : (
        // Scrolls internally so a long conversation doesn't grow the page. Only this list scrolls.
        <div className="max-h-[600px] overflow-y-auto pr-1 [&::-webkit-scrollbar]:w-1.5 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-teal-400 [&::-webkit-scrollbar-track]:bg-transparent">
          <ol className="flex flex-col gap-3">
            {data.map((message) => (
              <MessageCard key={message.id} message={message} />
            ))}
          </ol>
          {hasMore ? (
            <p className="mt-3 text-center text-xs text-muted-foreground">
              Showing the earliest messages in this conversation.
            </p>
          ) : null}
        </div>
      )}
    </div>
  );
}
