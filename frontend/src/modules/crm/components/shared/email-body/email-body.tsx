"use client";

import type { JSONContent } from "@tiptap/react";
import { NoteBody } from "@/modules/crm/features/leads/components/note-body";
import { EmailHtmlFrame } from "@/modules/crm/components/shared/email-body/email-html-frame";
import { EmailPlainText } from "@/modules/crm/components/shared/email-body/email-plain-text";

type Props = {
  /** Canonical plain text — always present. */
  body: string;
  /** A formatted reply sent from the CRM: the Tiptap document, rendered read-only. */
  bodyDoc: JSONContent | null;
  /** A received email's HTML part, already sanitised by the server. */
  bodyHtml: string | null;
  className?: string;
};

/**
 * One email's body, in the richest form available:
 *
 *   bodyDoc   → NoteBody, exactly as the thread has rendered formatted replies since they shipped
 *   bodyHtml  → EmailHtmlFrame (sandboxed, scripts off, remote images blocked until asked)
 *   otherwise → EmailPlainText
 *
 * Presentation only: which fields exist is the server's decision, and nothing here sends or stores.
 */
export function EmailBody({ body, bodyDoc, bodyHtml, className }: Props) {
  if (bodyDoc) return <NoteBody text={body} doc={bodyDoc} className={className} />;
  if (bodyHtml) return <EmailHtmlFrame html={bodyHtml} className={className} />;
  return <EmailPlainText text={body} className={className} />;
}
