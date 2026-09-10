"use client";

import Link from "next/link";
import { Wand2 } from "lucide-react";

import { PageHeader } from "@/core/components/page-header";
import { useKioskCatalog } from "@/modules/kiosk/hooks";

export default function KioskOverviewPage() {
  const { data: catalog, isLoading } = useKioskCatalog();

  return (
    <>
      <PageHeader
        title="Website Kiosk"
        subtitle="Build a client's website mockup — pick options, preview the look, capture the lead."
      />

      {/* Start CTA */}
      <div className="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-card border border-border bg-card p-6">
        <div>
          <h2 className="text-h3 font-bold text-foreground">Start a new website</h2>
          <p className="mt-1 text-sm text-muted-foreground">
            Walk a prospect through business type → website type → sections → brand kit, then show
            them the assembled mockup.
          </p>
        </div>
        <Link
          href="/kiosk/new"
          className="inline-flex items-center gap-2 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90"
        >
          <Wand2 className="size-4" /> New Website
        </Link>
      </div>

      {/* Catalog preview — proves the option set is wired up */}
      {isLoading ? (
        <p className="mt-6 text-sm text-muted-foreground">Loading catalog…</p>
      ) : catalog ? (
        <div className="mt-6 grid gap-4 lg:grid-cols-2">
          <Card title="Business types" count={catalog.business_types.length}>
            <ChipList items={catalog.business_types.map((b) => b.label)} />
          </Card>

          <Card title="Website types" count={catalog.website_types.length}>
            <ChipList items={catalog.website_types.map((w) => w.label)} />
          </Card>

          <Card title="Layout slots" count={catalog.sections.length}>
            <div className="space-y-2">
              {catalog.sections.map((s) => (
                <div key={s.slot} className="text-sm">
                  <span className="font-semibold text-foreground">{s.label}</span>{" "}
                  <span className="text-caption text-muted-foreground">
                    {s.variants.length} layout{s.variants.length === 1 ? "" : "s"}
                  </span>
                </div>
              ))}
            </div>
          </Card>

          <Card title="Content sections" count={catalog.page_sections.length}>
            <div className="flex flex-wrap gap-1.5">
              {catalog.page_sections.map((p) => (
                <span key={p.key} className="rounded-full bg-muted px-2.5 py-1 text-caption text-foreground">
                  {p.label} · {p.variants.length}
                </span>
              ))}
            </div>
          </Card>

          <Card title="Brand kits" count={catalog.brand_kits.length}>
            <div className="space-y-3">
              {catalog.brand_kits.map((kit) => (
                <div key={kit.key} className="flex items-center gap-3">
                  <div className="flex gap-1">
                    {[kit.palette.primary, kit.palette.accent, kit.palette.text].map((c) => (
                      <span
                        key={c}
                        className="size-5 rounded-full border border-border"
                        style={{ backgroundColor: c }}
                      />
                    ))}
                  </div>
                  <div className="text-sm">
                    <span className="font-semibold text-foreground">{kit.label}</span>{" "}
                    <span className="text-caption text-muted-foreground">
                      {kit.fonts.heading} / {kit.fonts.body}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          </Card>
        </div>
      ) : (
        <p className="mt-6 text-sm text-muted-foreground">Couldn’t load the catalog.</p>
      )}
    </>
  );
}

function Card({ title, count, children }: { title: string; count: number; children: React.ReactNode }) {
  return (
    <div className="rounded-card border border-border bg-card p-5">
      <div className="flex items-baseline justify-between">
        <h3 className="text-sm font-bold uppercase tracking-wide text-muted-foreground">{title}</h3>
        <span className="text-caption font-semibold text-muted-foreground">{count}</span>
      </div>
      <div className="mt-3">{children}</div>
    </div>
  );
}

function ChipList({ items }: { items: string[] }) {
  return (
    <div className="flex flex-wrap gap-1.5">
      {items.map((label) => (
        <span key={label} className="rounded-full bg-muted px-2.5 py-1 text-caption text-foreground">
          {label}
        </span>
      ))}
    </div>
  );
}
