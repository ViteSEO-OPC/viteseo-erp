"use client";

import { useState } from "react";
import type { Editor } from "@tiptap/react";
import { Send } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import { formatActivityTimestamp } from "@/modules/crm/lib/formatters";
import { useLeadEmails } from "@/modules/crm/features/leads/hooks/use-lead-emails";
import { useSendEmail } from "@/modules/crm/features/follow-ups/hooks/use-outreach-mutations";
import { Button } from "@/modules/crm/components/ui/button";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { EmailBody } from "@/modules/crm/components/shared/email-body/email-body";
import { EmailRichEditor } from "@/modules/crm/features/leads/components/email-rich-editor";
import type { EmailThreadMessage } from "@/modules/crm/features/leads/types/lead-detail-types";

/** Human label for the delivery/lifecycle state stored on the email row. */
function deliveryLabel(status: string): string {
  return status.charAt(0).toUpperCase() + status.slice(1);
}

/**
 * SENT / RECEIVED rather than outbound/inbound: the row is read from the CRM's point of view, and
 * "we sent this" / "they replied" is the distinction a BD actually scans for.
 */
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
      {inbound ? "Received" : "Sent"}
    </span>
  );
}

function MessageCard({ message }: { message: EmailThreadMessage }) {
  const inbound = message.direction === "inbound";

  // from → to for BOTH directions, so the shared-mailbox identity is visible on every row:
  //   sent     business@… → lead@…
  //   received lead@…     → business@…
  // Addresses come from the Email row (outbound from_email is written from the configured outreach
  // identity, inbound from the actual sender) — never hardcoded here, so changing the shared mailbox
  // in backend config changes this display with no frontend edit.
  const fromLabel = message.fromEmail ?? (inbound ? message.fromName : null) ?? "Shared mailbox";
  const toLabel = message.to ?? "—";

  // Attribution for an outbound send: which CRM user performed it. Resolved server-side from the
  // Email's sender relation, so it follows lead assignment rather than any fixed user.
  const attribution = inbound
    ? message.fromName
      ? `from ${message.fromName}`
      : null
    : message.senderName
      ? `sent by ${message.senderName}`
      : null;

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

      <p className="mt-2 truncate text-xs text-muted-foreground">
        <span className="font-medium text-foreground/80">{fromLabel}</span>
        <span aria-hidden className="mx-1.5">
          →
        </span>
        <span className="font-medium text-foreground/80">{toLabel}</span>
        {attribution ? <span className="ml-1.5">· {attribution}</span> : null}
      </p>

      {/* `break-words` like the body below: an inbound subject is whatever the sender typed. */}
      <p className="mt-1 break-words text-sm font-semibold text-card-foreground">
        {message.subject || "(no subject)"}
      </p>
      {/* A formatted reply still renders through NoteBody (ProseMirror from JSON); a received HTML
          email renders in a sandboxed frame; everything else as structured plain text. There is no
          dangerouslySetInnerHTML anywhere in the thread. */}
      <EmailBody
        body={message.body}
        bodyDoc={message.bodyDoc}
        bodyHtml={message.bodyHtml}
        className="mt-1"
      />
    </li>
  );
}

/** `Re:` the newest message's subject, without stacking a second prefix on an existing one. */
function replySubjectFor(messages: EmailThreadMessage[] | undefined): string {
  const latest = messages?.[messages.length - 1];
  const subject = latest?.subject?.trim();
  if (!subject) return "";
  return /^re:/i.test(subject) ? subject : `Re: ${subject}`;
}

type Props = {
  leadId: string;
};

/**
 * Email conversation for a lead (outbound + inbound), chronologically ordered, with a reply box.
 *
 * Reads through `GET /leads/{lead}/emails` (ownership-enforced by the route's `view` policy) and
 * sends through the EXISTING Outreach pipeline — `useSendEmail` → `POST /leads/{lead}/emails` →
 * OutreachEmailService. No second email path is introduced here: this component owns presentation
 * and nothing else. The customer-facing sender identity stays entirely a backend/config concern.
 *
 * The reply is composed as a formatted document and sent as `body_doc` alongside `body`. The server
 * validates the document against the email-safe subset, derives the plain text from it, and writes
 * the HTML part itself — the browser never sends markup.
 */
export function EmailThread({ leadId }: Props) {
  const { data, hasMore, isLoading, isError } = useLeadEmails(leadId);
  const sendEmail = useSendEmail();

  // The editor owns the draft. These two are its handle and an empty/non-empty flag — the editor
  // instance is referentially stable, so reading its text during render would never update.
  const [editor, setEditor] = useState<Editor | null>(null);
  const [hasBody, setHasBody] = useState(false);
  // `null` means "follow the thread" — the derived Re: subject is used until the BD edits it, so a
  // newly-arrived reply keeps the box in sync without an effect syncing state to props.
  const [subjectOverride, setSubjectOverride] = useState<string | null>(null);
  const subject = subjectOverride ?? replySubjectFor(data);

  const canSend = editor !== null && hasBody && !!subject.trim() && !sendEmail.isPending;

  function submit() {
    if (!canSend || !editor) return;
    sendEmail.mutate(
      {
        leadId,
        subject: subject.trim(),
        // Advisory only: the server re-derives `body` from the document, so the text/plain part and
        // the HTML part come from one source. Still sent because `body` is required for every caller.
        body: editor.getText({ blockSeparator: "\n" }).trim(),
        bodyDoc: editor.getJSON(),
      },
      {
        onSuccess: () => {
          // The thread refreshes via useSendEmail's invalidation of leads.emails(leadId).
          editor.commands.clearContent(true);
          setSubjectOverride(null);
        },
      },
    );
  }

  return (
    // `contain-inline-size`, for the same reason as the Files & Downloads card: the card's WIDTH never
    // depends on what is inside it. The from → to line is `truncate`, and `break-words` still lets a
    // long URL in a body count at full width, so either used to climb to the page's `76fr` track, whose
    // bare `fr` cannot shrink below its content. The card always fills its column, so nothing in it
    // relies on its intrinsic width.
    <div className="rounded-2xl border border-border bg-card p-6 shadow-card contain-inline-size">
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
        // Bounded MESSAGE HISTORY only — the reply composer below is deliberately outside this
        // element. A lead can accumulate an unbounded number of messages, and letting the card grow
        // with them would make Lead Details arbitrarily long.
        //
        // 440px ≈ three message cards. Measured against real rows: a card runs ~127px (~147px with a
        // longer body) and the list gap is 12px, so three cards plus their two gaps is ~425px and
        // fits without clipping, while a fourth (~564px total) starts the scroll. A height cap rather
        // than a JS row count keeps it content-driven: one short message still renders at its natural
        // height with NO scrollbar. This is the card's one internal scroll surface — the card itself
        // never becomes a scroller.
        <div className="max-h-[440px] overflow-y-auto pr-1 [&::-webkit-scrollbar]:w-1.5 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-teal-400 [&::-webkit-scrollbar-track]:bg-transparent">
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

      {/* Reply — hidden only while the thread is still resolving, so the composer never renders
          against an unknown subject. A failed LOAD still allows a send: the two are independent. */}
      {!isLoading ? (
        <div className="mt-5 border-t border-border pt-5">
          <h4 className="mb-3 text-sm font-semibold text-card-foreground">Reply</h4>

          {/* Labelled with `aria-label` rather than a visually-hidden <label>, matching
              DiscussionNotes. NOT a decorative choice: Tailwind's `sr-only` is `position:absolute`,
              and because the AppShell's <main> scroll container is `position:static` it is not in an
              absolutely-positioned descendant's containing-block chain — so an `sr-only` element here
              escapes main's `overflow-y:auto` clipping, lays out against the initial containing block
              at its in-flow offset (~1588px down this page), and extends the DOCUMENT's scrollHeight.
              That produced a second, outer browser scrollbar and ~1000px of blank space below the
              app shell. `aria-label` gives the same accessible name with no positioned box. */}
          <input
            aria-label="Reply subject"
            type="text"
            value={subject}
            onChange={(e) => setSubjectOverride(e.target.value)}
            maxLength={255}
            placeholder="Subject"
            className="h-10 w-full rounded-lg border border-border bg-card px-3.5 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
          />

          <EmailRichEditor
            className="mt-2"
            ariaLabel="Reply message"
            placeholder="Write your reply…"
            onEditorReady={setEditor}
            onEmptyChange={setHasBody}
            onSubmit={submit}
            disabled={sendEmail.isPending}
          />

          <div className="mt-3 flex justify-end">
            <Button size="sm" onClick={submit} disabled={!canSend}>
              <Send className="h-4 w-4" />
              {sendEmail.isPending ? "Sending…" : "Send Reply"}
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  );
}
