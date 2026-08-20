"use client";

import { useState } from "react";
import { AlertTriangle, ChevronDown, Mail, Paperclip } from "lucide-react";

import { cn } from "@/lib/utils";
import { formatDateTime } from "@/core/lib/format";
import { EMAIL_PREVIEW_CLASSES } from "@/modules/hr/lib/email-editor-styles";
import { useCandidateEmails } from "@/modules/hr/hooks/use-recruitment";
import { STAGE_EMAIL, type CandidateEmail } from "@/modules/hr/types/recruitment";

/** Type → the same words the board uses for it. */
const TYPE_LABEL: Record<string, string> = Object.values(STAGE_EMAIL).reduce(
  (acc, entry) => ({ ...acc, [entry.type]: entry.title }),
  {},
);

/** Every email sent to a candidate, newest first. */
export function CandidateEmailHistory({ candidateId }: { candidateId: number }) {
  const { data, isLoading } = useCandidateEmails(candidateId);

  if (isLoading) {
    return <div className="h-16 animate-pulse rounded-lg bg-black/5" aria-busy="true" />;
  }
  if (!data?.length) return null;

  return (
    <div className="space-y-1.5">
      <p className="text-label font-semibold uppercase tracking-wide text-brand">
        Emails sent
      </p>
      <ul className="space-y-1.5">
        {data.map((email) => (
          <EmailRow key={email.id} email={email} />
        ))}
      </ul>
    </div>
  );
}

function EmailRow({ email }: { email: CandidateEmail }) {
  const [open, setOpen] = useState(false);
  const failed = email.status === "failed";

  return (
    <li className="rounded-lg border border-border">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className="flex w-full items-center gap-2 px-3 py-2 text-left"
      >
        {failed ? (
          <AlertTriangle className="size-3.5 shrink-0 text-destructive" />
        ) : (
          <Mail className="size-3.5 shrink-0 text-info" />
        )}
        <span className="min-w-0 flex-1">
          <span className="block truncate text-sm font-medium text-foreground">
            {email.subject}
          </span>
          <span className="block truncate text-caption text-muted-foreground">
            {TYPE_LABEL[email.type] ?? email.type} · {email.to_email}
            {email.sent_at ? ` · ${formatDateTime(email.sent_at)}` : " · not delivered"}
          </span>
        </span>
        {email.attachments.length > 0 && (
          <Paperclip className="size-3.5 shrink-0 text-muted-foreground" />
        )}
        <ChevronDown
          className={cn(
            "size-4 shrink-0 text-muted-foreground transition-transform",
            open && "rotate-180",
          )}
          aria-hidden
        />
      </button>

      {open && (
        <div className="space-y-2 border-t border-border px-3 py-2.5">
          {failed && (
            <p className="rounded-md bg-destructive/10 px-2.5 py-1.5 text-caption text-destructive">
              This email was not delivered. {email.error}
            </p>
          )}

          <dl className="space-y-0.5 text-caption text-muted-foreground">
            {email.cc.length > 0 && <MetaRow label="Cc" value={email.cc.join(", ")} />}
            {email.bcc.length > 0 && <MetaRow label="Bcc" value={email.bcc.join(", ")} />}
            {email.reply_to && <MetaRow label="Reply-to" value={email.reply_to} />}
            {email.sent_by && <MetaRow label="Sent by" value={email.sent_by} />}
          </dl>

          {/*
           * Server-sanitised on the way in (HtmlSanitizer), and re-read from the same
           * column that was transmitted — this is the message as the candidate got it.
           */}
          <div
            className={cn("rounded-lg border border-border bg-muted/40 px-3 py-2", EMAIL_PREVIEW_CLASSES)}
            dangerouslySetInnerHTML={{ __html: email.body_html }}
          />

          {email.attachments.length > 0 && (
            <ul className="space-y-1">
              {email.attachments.map((file) => (
                <li key={file.name} className="flex items-center gap-1.5">
                  <Paperclip className="size-3.5 shrink-0 text-muted-foreground" />
                  {file.url ? (
                    <a
                      href={file.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="min-w-0 truncate text-caption font-medium text-brand hover:underline"
                    >
                      {file.name}
                    </a>
                  ) : (
                    <span className="min-w-0 truncate text-caption">{file.name}</span>
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </li>
  );
}

function MetaRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex gap-1.5">
      <dt className="shrink-0">{label}:</dt>
      <dd className="min-w-0 break-words text-foreground">{value}</dd>
    </div>
  );
}
