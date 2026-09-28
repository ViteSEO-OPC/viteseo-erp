"use client";

import { Fragment, useId, useMemo, useState } from "react";
import { cn } from "@/modules/crm/lib/utils";
import { linkifyText, parsePlainTextEmail, type PlainBlock } from "@/modules/crm/lib/email-body";
import { QuotedTextToggle } from "@/modules/crm/components/shared/email-body/quoted-text-toggle";

// Same link treatment as a formatted note (RICH_TEXT_PROSE_CLASSES).
const LINK_CLASSES = "text-teal-700 underline underline-offset-2 dark:text-teal-400";

/** Text with its URLs and addresses as links. Every href comes from `linkifyText`'s scheme allow-list. */
function LinkifiedText({ text }: { text: string }) {
  return linkifyText(text).map((token, i) =>
    token.kind === "link" ? (
      <a key={i} href={token.href} target="_blank" rel="noopener noreferrer" className={LINK_CLASSES}>
        {token.text}
      </a>
    ) : (
      <Fragment key={i}>{token.text}</Fragment>
    ),
  );
}

function Blocks({ blocks }: { blocks: PlainBlock[] }) {
  return blocks.map((block, i) =>
    block.kind === "quote" ? (
      <blockquote key={i} className="my-1.5 border-l-2 border-border pl-3 text-muted-foreground">
        <LinkifiedText text={block.text} />
      </blockquote>
    ) : (
      <p key={i} className="my-1.5 first:mt-0 last:mb-0">
        <LinkifiedText text={block.text} />
      </p>
    ),
  );
}

/**
 * A plain-text email, read as an email: clickable links, `>` quotes as quote blocks, the signature
 * set back, and the earlier-messages history collapsed behind "Show quoted text".
 *
 * Rendered entirely as React text nodes — there is no markup in this path to escape or sanitise.
 * `wrap-anywhere` for the same reason as NoteBody: a long unbroken URL must not size the grid track.
 */
export function EmailPlainText({ text, className }: { text: string; className?: string }) {
  const email = useMemo(() => parsePlainTextEmail(text), [text]);
  const [showQuoted, setShowQuoted] = useState(false);
  const quotedId = useId();

  return (
    <div className={cn("wrap-anywhere whitespace-pre-wrap text-sm leading-relaxed text-foreground", className)}>
      <Blocks blocks={email.main} />

      {email.signature !== null ? (
        <p className="mt-2 text-xs text-muted-foreground">
          <LinkifiedText text={email.signature} />
        </p>
      ) : null}

      {email.quoted !== null ? (
        <>
          <QuotedTextToggle
            expanded={showQuoted}
            onToggle={() => setShowQuoted((shown) => !shown)}
            controls={quotedId}
            className="mt-1 -ml-2"
          />
          <div id={quotedId} hidden={!showQuoted} className="mt-1 border-l-2 border-border pl-3 text-muted-foreground">
            <Blocks blocks={email.quoted} />
          </div>
        </>
      ) : null}
    </div>
  );
}
