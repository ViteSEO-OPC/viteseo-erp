"use client";

import { useEffect, useId, useMemo, useRef, useState } from "react";
import { ImageOff } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { cn } from "@/modules/crm/lib/utils";
import {
  EMAIL_FRAME_SANDBOX,
  QUOTED_HISTORY_SELECTOR,
  QUOTES_COLLAPSED_CLASS,
  buildEmailSrcDoc,
  hasRemoteContent,
  isSafeEmailHref,
} from "@/modules/crm/lib/email-body";
import { QuotedTextToggle } from "@/modules/crm/components/shared/email-body/quoted-text-toggle";

/** Height before the document has been measured — roughly one short message. */
const INITIAL_HEIGHT = 120;

/**
 * Links keep an href only for http(s), mailto and tel, and always open in a new tab with no opener.
 * The frame's own policy already refuses `javascript:` navigation; this is the third layer after the
 * server's sanitiser and that policy, and it also removes the reverse-tabnabbing handle a bare
 * `target=_blank` would give the opened page.
 */
function hardenLinks(doc: Document) {
  for (const anchor of doc.querySelectorAll("a, area")) {
    const href = anchor.getAttribute("href");
    if (href === null) continue;
    if (!isSafeEmailHref(href)) {
      anchor.removeAttribute("href");
      continue;
    }
    anchor.setAttribute("target", "_blank");
    anchor.setAttribute("rel", "noopener noreferrer");
  }
}

/**
 * True when the email has recognisable quoted history AND something outside it to read. A message that
 * is entirely history (a bare forward, say) is never collapsed — hiding it would hide the email.
 */
function hasCollapsibleHistory(doc: Document): boolean {
  if (!doc.body.querySelector(QUOTED_HISTORY_SELECTOR)) return false;

  const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT);
  for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
    const parent = node.parentElement;
    if (parent === null || !node.textContent?.trim()) continue;
    if (parent.closest("style, title, script")) continue;
    if (!parent.closest(QUOTED_HISTORY_SELECTOR)) return true;
  }
  return false;
}

/**
 * An HTML email, shown the way a mail client shows it.
 *
 * The markup never touches the CRM's DOM: it is the `srcDoc` of a sandboxed frame with scripts off and
 * a policy that blocks remote loads (see lib/email-body). The sender's styles stay inside the frame and
 * the CRM's stay out. The frame grows to its content, so the message scrolls with the thread rather
 * than inside a box of its own.
 *
 * Remote images stay blocked until the viewer clicks "Show images", which rebuilds the document with
 * images allowed. Quoted history is hidden by toggling a class on the frame's <html> — no reload.
 */
export function EmailHtmlFrame({
  html,
  trustedImageSources,
  className,
}: {
  html: string;
  /** Path sources whose images load without "Show images" — see `emailFrameCsp`. */
  trustedImageSources?: readonly string[];
  className?: string;
}) {
  const frameRef = useRef<HTMLIFrameElement>(null);
  const observerRef = useRef<ResizeObserver | null>(null);
  const frameId = useId();

  const [height, setHeight] = useState(INITIAL_HEIGHT);
  const [showRemoteImages, setShowRemoteImages] = useState(false);
  const [hasQuotedHistory, setHasQuotedHistory] = useState(false);
  const [quotedExpanded, setQuotedExpanded] = useState(false);

  // Keyed by value, not identity, so a caller passing a fresh array each render does not rebuild the
  // document (which would reload the frame).
  const trustedKey = trustedImageSources?.join(" ") ?? "";
  const trusted = useMemo(() => (trustedKey === "" ? [] : trustedKey.split(" ")), [trustedKey]);
  const remoteContent = useMemo(() => hasRemoteContent(html, trusted), [html, trusted]);
  const srcDoc = useMemo(
    () => buildEmailSrcDoc(html, { showRemoteImages, trustedImageSources: trusted }),
    [html, showRemoteImages, trusted],
  );

  useEffect(() => () => observerRef.current?.disconnect(), []);

  function measure() {
    const body = frameRef.current?.contentDocument?.body;
    if (!body) return;
    const next = Math.ceil(body.getBoundingClientRect().height);
    if (next > 0) setHeight(next);
  }

  function setCollapsed(doc: Document, collapsed: boolean) {
    doc.documentElement.classList.toggle(QUOTES_COLLAPSED_CLASS, collapsed);
  }

  // Runs on every (re)load — the first render, and again after "Show images" rebuilds the document.
  function handleLoad() {
    const frame = frameRef.current;
    const doc = frame?.contentDocument;
    if (!frame || !doc?.body) return;

    hardenLinks(doc);

    const collapsible = hasCollapsibleHistory(doc);
    setHasQuotedHistory(collapsible);
    setCollapsed(doc, collapsible && !quotedExpanded);
    measure();

    // Re-measure when the thread's width changes (text re-wraps) or the content itself resizes.
    observerRef.current?.disconnect();
    const observer = new ResizeObserver(() => measure());
    observer.observe(frame);
    observer.observe(doc.body);
    observerRef.current = observer;
  }

  function toggleQuoted() {
    const expanded = !quotedExpanded;
    setQuotedExpanded(expanded);
    const doc = frameRef.current?.contentDocument;
    if (doc?.body) {
      setCollapsed(doc, !expanded);
      measure();
    }
  }

  return (
    <div className={cn("min-w-0", className)}>
      {remoteContent && !showRemoteImages ? (
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2 rounded-md bg-muted px-3 py-1.5 text-xs text-muted-foreground">
          <span className="inline-flex items-center gap-1.5">
            <ImageOff className="size-3.5 shrink-0" aria-hidden />
            Remote images are hidden to protect your privacy.
          </span>
          <Button type="button" variant="outline" size="xs" onClick={() => setShowRemoteImages(true)}>
            Show images
          </Button>
        </div>
      ) : null}

      <div className="overflow-hidden rounded-md border border-border bg-white">
        <iframe
          ref={frameRef}
          id={frameId}
          title="Email message"
          sandbox={EMAIL_FRAME_SANDBOX}
          srcDoc={srcDoc}
          referrerPolicy="no-referrer"
          onLoad={handleLoad}
          className="block w-full border-0"
          style={{ height }}
        />
      </div>

      {hasQuotedHistory ? (
        <QuotedTextToggle
          expanded={quotedExpanded}
          onToggle={toggleQuoted}
          controls={frameId}
          className="mt-1 -ml-2"
        />
      ) : null}
    </div>
  );
}
