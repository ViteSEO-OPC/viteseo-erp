"use client";

import { useState } from "react";
import Link from "next/link";
import { Wand2 } from "lucide-react";

import { PageHeader } from "@/core/components/page-header";
import { Modal } from "@/core/ui/modal";
import { useConfirm } from "@/core/ui/use-confirm";
import { useKioskSelections, useDeleteSelection, useKioskCatalog } from "@/modules/kiosk/hooks";
import { KioskPreview, PreviewFrame, ExportButton } from "@/modules/kiosk/components/kiosk-preview";
import { resolveTheme } from "@/modules/kiosk/theme";
import type { SiteInput, SitePage } from "@/modules/kiosk/site-builder";
import type { KioskSelection, KioskSitePage } from "@/modules/kiosk/types";

/** Build the site-builder input from a saved selection (tolerates old data). */
function toSiteInput(l: KioskSelection): SiteInput {
  const s = l.sections ?? {};
  const rawPages = (s.pages ?? []) as KioskSitePage[];
  const pages: SitePage[] = rawPages.map((p, i) => ({
    name: p.name ?? "Home",
    slug: p.slug ?? (i === 0 ? "home" : `page-${i + 1}`),
    hero: p.hero ?? null,
    sections: (p.sections ?? []).map((sc) => ({ section: sc.section, variant: sc.variant })),
  }));
  return {
    businessName: l.prospect_name,
    websiteType: l.website_type,
    header: s.header ?? null,
    footer: s.footer ?? null,
    pages,
    logo: l.logo,
  };
}

function when(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" });
}

export default function KioskLeadsPage() {
  const { data: leads, isLoading } = useKioskSelections();
  const { data: catalog } = useKioskCatalog();
  const remove = useDeleteSelection();
  const { confirm, confirmDialog } = useConfirm();
  const [previewing, setPreviewing] = useState<KioskSelection | null>(null);

  const list = leads ?? [];
  const previewTheme = previewing
    ? resolveTheme(catalog, {
        brand_kit: previewing.brand_kit,
        typography: previewing.typography,
        type_scale: previewing.type_scale,
        design_style: previewing.design_style,
        heading_style: previewing.heading_style,
        radius: previewing.radius,
        cta_hover: previewing.cta_hover,
      })
    : null;

  return (
    <>
      {confirmDialog}

      <Modal
        open={previewing !== null}
        onClose={() => setPreviewing(null)}
        title={previewing ? `${previewing.prospect_name} — mockup` : "Preview"}
        width={900}
      >
        {previewing && previewTheme ? (
          <div>
            <div className="mb-2 flex justify-end">
              <ExportButton input={toSiteInput(previewing)} theme={previewTheme} />
            </div>
            <PreviewFrame>
              <KioskPreview input={toSiteInput(previewing)} theme={previewTheme} />
            </PreviewFrame>
          </div>
        ) : (
          <p className="text-sm text-muted-foreground">Loading preview…</p>
        )}
      </Modal>

      <div className="flex flex-wrap items-start justify-between gap-4">
        <PageHeader title="Leads" subtitle="Saved website selections captured by the team." />
        <Link
          href="/kiosk/new"
          className="mt-1 inline-flex items-center gap-2 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90"
        >
          <Wand2 className="size-4" /> New Website
        </Link>
      </div>

      <div className="mt-6 overflow-x-auto rounded-card border border-border bg-card">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-border">
              <th className="px-4 py-2.5 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">Prospect</th>
              <th className="px-4 py-2.5 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">Business</th>
              <th className="px-4 py-2.5 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">Website</th>
              <th className="px-4 py-2.5 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">Pages</th>
              <th className="px-4 py-2.5 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">By</th>
              <th className="px-4 py-2.5 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">Date</th>
              <th className="px-4 py-2.5" />
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <tr><td colSpan={7} className="px-4 py-6 text-center text-muted-foreground">Loading…</td></tr>
            ) : list.length === 0 ? (
              <tr><td colSpan={7} className="px-4 py-6 text-center text-muted-foreground">No leads yet — start a New Website.</td></tr>
            ) : (
              list.map((l: KioskSelection) => (
                <tr key={l.id} className="border-b border-border last:border-0">
                  <td className="px-4 py-2.5 font-medium text-foreground">
                    {l.prospect_name}
                    {l.prospect_contact ? <div className="text-caption text-muted-foreground">{l.prospect_contact}</div> : null}
                  </td>
                  <td className="px-4 py-2.5 text-muted-foreground">{l.business_type_label}</td>
                  <td className="px-4 py-2.5 text-muted-foreground">{l.website_type_label}</td>
                  <td className="px-4 py-2.5 text-muted-foreground">{(l.sections.pages ?? []).length}</td>
                  <td className="px-4 py-2.5 text-muted-foreground">{l.created_by ?? "—"}</td>
                  <td className="px-4 py-2.5 text-muted-foreground">{when(l.created_at)}</td>
                  <td className="px-4 py-2.5 text-right">
                    <div className="flex items-center justify-end gap-3">
                      <button
                        type="button"
                        onClick={() => setPreviewing(l)}
                        className="text-caption font-semibold text-brand hover:underline"
                      >
                        Preview
                      </button>
                      <button
                        type="button"
                        onClick={() =>
                          confirm({
                            title: `Delete the lead for ${l.prospect_name}?`,
                            confirmLabel: "Delete",
                            onConfirm: () => remove.mutate(l.id),
                          })
                        }
                        className="text-caption font-semibold text-destructive hover:underline"
                      >
                        Delete
                      </button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </>
  );
}
