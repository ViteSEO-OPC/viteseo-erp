"use client";

import { useMemo } from "react";
import { Download } from "lucide-react";

import { buildSite, type KioskTheme, type SiteInput } from "../site-builder";

/**
 * Renders the assembled site (from site-builder) in a sandboxed iframe, so the
 * real CSS + JS (scroll reveal, parallax) run exactly as they will for the
 * client — and identically to the exported file.
 */
export function KioskPreview({ input, theme, height = "68vh" }: { input: SiteInput; theme: KioskTheme; height?: string }) {
  const html = useMemo(() => buildSite(input, theme), [input, theme]);

  return (
    <iframe
      title="Website preview"
      srcDoc={html}
      sandbox="allow-scripts"
      className="block w-full"
      style={{ height, border: 0, background: "#fff" }}
    />
  );
}

const slugify = (s: string): string =>
  s.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "") || "website";

/** Downloads the assembled site as one self-contained .html (inline CSS + JS). */
export function ExportButton({ input, theme }: { input: SiteInput; theme: KioskTheme }) {
  const download = () => {
    const html = buildSite(input, theme);
    const blob = new Blob([html], { type: "text/html;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `${slugify(input.businessName || "website")}.html`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  };

  return (
    <button
      type="button"
      onClick={download}
      className="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-1.5 text-caption font-semibold text-foreground transition-colors hover:bg-muted/40"
    >
      <Download className="size-3.5" /> Export HTML/CSS/JS
    </button>
  );
}

/** A browser-chrome frame around the preview. */
export function PreviewFrame({ children }: { children: React.ReactNode }) {
  return (
    <div className="overflow-hidden rounded-xl border border-border shadow-sm">
      <div className="flex items-center gap-1.5 border-b border-border bg-muted/40 px-3 py-2">
        <span className="size-2.5 rounded-full bg-destructive/60" />
        <span className="size-2.5 rounded-full bg-warning/60" />
        <span className="size-2.5 rounded-full bg-brand/60" />
        <span className="ml-2 h-4 flex-1 rounded bg-background" />
      </div>
      {children}
    </div>
  );
}
