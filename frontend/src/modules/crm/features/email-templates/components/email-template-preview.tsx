"use client";

import { EmailPlainText } from "@/modules/crm/components/shared/email-body/email-plain-text";
import { renderSample } from "@/modules/crm/features/email-templates/lib/email-template-text";
import type { TemplateToken } from "@/modules/crm/features/email-templates/types/email-template-types";

type Props = {
  subject: string;
  body: string;
  tokens: TemplateToken[];
};

/**
 * The template as a recipient would read it, filled in with the catalog's sample CRM data. The body
 * goes through the same plain-text renderer as Lead Details → Email Conversation, so links, quotes and
 * the signature look here the way they will look there. No request is made: nothing is rendered for a
 * real lead.
 */
export function EmailTemplatePreview({ subject, body, tokens }: Props) {
  const sample = (key: string) => tokens.find((token) => token.key === key)?.sample ?? "";
  const renderedSubject = renderSample(subject, tokens).trim();
  const renderedBody = renderSample(body, tokens);

  return (
    <div className="flex flex-col gap-3">
      <p className="text-xs text-muted-foreground">
        Previewed with sample CRM data. Every real email uses its own lead&apos;s details.
      </p>

      <article className="rounded-lg border border-border bg-background">
        <header className="border-b border-border px-4 py-3">
          <p className="break-words text-base font-semibold text-foreground">
            {renderedSubject || "(no subject)"}
          </p>
          <p className="mt-1 truncate text-xs text-muted-foreground">
            <span className="font-medium text-foreground/80">{sample("sender_name")}</span>
            <span aria-hidden className="mx-1.5">
              →
            </span>
            <span className="font-medium text-foreground/80">{sample("contact_name")}</span>
            {sample("business_name") ? <span> · {sample("business_name")}</span> : null}
          </p>
        </header>

        <div className="px-4 py-4">
          {renderedBody.trim() ? (
            <EmailPlainText text={renderedBody} />
          ) : (
            <p className="text-sm text-muted-foreground">Nothing to preview yet.</p>
          )}
        </div>
      </article>
    </div>
  );
}
