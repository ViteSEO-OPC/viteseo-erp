"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { ArrowLeft, ArrowRight, Check, Upload, Image as ImageIcon, Smartphone, Tablet, Monitor, GripVertical, Copy, Trash2, ChevronUp, ChevronDown } from "lucide-react";
import { DndContext, closestCenter, PointerSensor, KeyboardSensor, useSensor, useSensors, type DragEndEvent } from "@dnd-kit/core";
import { SortableContext, verticalListSortingStrategy, arrayMove, sortableKeyboardCoordinates, useSortable } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";

import { PageHeader } from "@/core/components/page-header";
import { notify } from "@/core/lib/notify";
import { useKioskCatalog, useCreateSelection } from "@/modules/kiosk/hooks";
import { KioskPreview, PreviewFrame, ExportButton } from "@/modules/kiosk/components/kiosk-preview";
import { resolveTheme } from "@/modules/kiosk/theme";
import type { KioskTheme, SiteInput } from "@/modules/kiosk/site-builder";
import type { KioskCatalog, KioskPageChoice, KioskPageSection, KioskSectionChoice, KioskSitePage } from "@/modules/kiosk/types";

/** Neutral theme for the per-option quick previews (before the client picks colours). */
const QUICKVIEW_THEME: KioskTheme = {
  palette: { primary: "#2E7D6B", accent: "#F2A65A", bg: "#FFFFFF", text: "#1B2A27" },
  fonts: { heading: "Poppins", body: "Inter" },
  scale: "standard",
  style: "clean",
  headingStyle: "standard",
  radius: "soft",
  ctaHover: "wipe",
  dark: false,
};

const inputCls =
  "h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

const STEPS = ["Prospect", "Business", "Website", "Sections", "Design", "Review"];

/** The piece the guest most recently touched — drives the "per-section" preview. */
type Focused =
  | { kind: "header"; variant: string }
  | { kind: "footer"; variant: string }
  | { kind: "hero"; variant: string }
  | { kind: "section"; section: string; variant: string };

/** Short client-side instance id — stable React key + drag/duplicate handle. */
const uid = (): string => Math.random().toString(36).slice(2, 9);

/** Default page keys for a niche (mirrors KioskCatalog::defaultPages on the API). */
function defaultPageKeys(businessType: string | null, websiteType: string | null): string[] {
  if (websiteType === "landing") return ["home", "contact"];
  const primary =
    websiteType === "ecommerce" ? "shop"
      : websiteType === "portfolio" ? "gallery"
        : websiteType === "booking" ? "services"
          : websiteType === "blog" ? "blog"
            : businessType === "retail" ? "shop"
              : businessType === "services" || businessType === "real_estate" || businessType === "education" ? "services"
                : businessType === "restaurant" || businessType === "hospitality" || businessType === "personal" ? "gallery"
                  : null;
  return [...new Set(["home", primary, "about", "contact"].filter(Boolean) as string[])];
}

/** Build fresh pages (with instance ids) from the catalog blueprints. */
function pagesFromBlueprints(catalog: KioskCatalog, keys: string[]): KioskSitePage[] {
  const byKey = new Map(catalog.page_blueprints.map((b) => [b.key, b]));
  return keys
    .map((k) => byKey.get(k))
    .filter((b): b is NonNullable<typeof b> => Boolean(b))
    .map((b) => ({
      id: uid(),
      name: b.name,
      slug: b.slug,
      hero: b.hero,
      sections: b.sections.map((s) => ({ id: uid(), section: s.section, variant: s.variant })),
    }));
}

interface Form {
  prospect_name: string;
  prospect_contact: string;
  notes: string;
  business_type: string | null;
  website_type: string | null;
  sections: KioskSectionChoice;
  brand_kit: string | null;
  typography: string | null;
  type_scale: string | null;
  design_style: string | null;
  heading_style: string | null;
  radius: string | null;
  cta_hover: string | null;
  logo: string | null;
}

const emptyForm: Form = {
  prospect_name: "",
  prospect_contact: "",
  notes: "",
  business_type: null,
  website_type: null,
  sections: { header: null, footer: null, pages: [] },
  brand_kit: null,
  typography: null,
  type_scale: "standard",
  design_style: "clean",
  heading_style: "standard",
  radius: "soft",
  cta_hover: "wipe",
  logo: null,
};

export default function KioskWizardPage() {
  const router = useRouter();
  const { data: catalog } = useKioskCatalog();
  const create = useCreateSelection();

  const [step, setStep] = useState(0);
  const [form, setForm] = useState<Form>(emptyForm);
  // Live-preview controls: show the whole site or just the last-touched section.
  const [previewMode, setPreviewMode] = useState<"whole" | "section">("whole");
  const [focused, setFocused] = useState<Focused | null>(null);
  // Which page (index) is being edited in the multi-page Sections step.
  const [activePage, setActivePage] = useState(0);

  const set = <K extends keyof Form>(key: K, value: Form[K]) => setForm((f) => ({ ...f, [key]: value }));

  // Drag-to-reorder sensors — pointer (mouse/touch) + keyboard for accessibility.
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  // Hybrid onboarding: seed the niche's default pages the first time the guest
  // reaches the Sections step — never clobbers pages they've already built.
  const seedPagesIfEmpty = () => {
    if (!catalog || !form.business_type || form.sections.pages.length > 0) return;
    const seeded = pagesFromBlueprints(catalog, defaultPageKeys(form.business_type, form.website_type));
    if (seeded.length) setForm((f) => ({ ...f, sections: { ...f.sections, pages: seeded } }));
  };

  // Logo → inline base64 data URI (kept self-contained for preview + export).
  const onLogoFile = (file: File | null) => {
    if (!file) return;
    const ok = ["image/png", "image/jpeg", "image/svg+xml", "image/webp"];
    if (!ok.includes(file.type)) {
      notify.error("Logo must be PNG, JPG, SVG, or WebP.");
      return;
    }
    if (file.size > 500 * 1024) {
      notify.error("Logo is too large — keep it under 500KB.");
      return;
    }
    const reader = new FileReader();
    reader.onload = () => set("logo", typeof reader.result === "string" ? reader.result : null);
    reader.onerror = () => notify.error("Couldn’t read that file — try another.");
    reader.readAsDataURL(file);
  };

  // Header + footer are site-wide slots.
  const setSlot = (slot: "header" | "footer", key: string) =>
    setForm((f) => ({ ...f, sections: { ...f.sections, [slot]: f.sections[slot] === key ? null : key } }));

  const pages = form.sections.pages;
  const activeIdx = Math.min(activePage, Math.max(0, pages.length - 1));
  const activePageObj = pages[activeIdx];

  // Patch the page currently being edited.
  const updateActivePage = (patch: Partial<KioskSitePage>) =>
    setForm((f) => ({
      ...f,
      sections: { ...f.sections, pages: f.sections.pages.map((p, i) => (i === activeIdx ? { ...p, ...patch } : p)) },
    }));

  const addPage = (key: string) => {
    const bp = catalog?.page_blueprints.find((b) => b.key === key);
    if (!bp) return;
    const taken = new Set(pages.map((p) => p.slug));
    let slug = bp.slug;
    for (let n = 2; taken.has(slug); n++) slug = `${bp.slug}-${n}`;
    const page: KioskSitePage = {
      id: uid(),
      name: bp.name,
      slug,
      hero: bp.hero,
      sections: bp.sections.map((s) => ({ id: uid(), section: s.section, variant: s.variant })),
    };
    setForm((f) => ({ ...f, sections: { ...f.sections, pages: [...f.sections.pages, page] } }));
    setActivePage(pages.length); // focus the new page
  };

  const removePage = (idx: number) => {
    if (pages.length <= 1) return; // always keep at least one page
    setForm((f) => ({ ...f, sections: { ...f.sections, pages: f.sections.pages.filter((_, i) => i !== idx) } }));
    setActivePage((a) => Math.max(0, Math.min(a >= idx && a > 0 ? a - 1 : a, pages.length - 2)));
  };

  const setPageHero = (key: string) => {
    updateActivePage({ hero: activePageObj?.hero === key ? null : key });
    if (activePageObj?.hero !== key) setFocused({ kind: "hero", variant: key });
  };

  // Section instances on the active page — added/removed/duplicated/reordered by
  // instance id (so the same section type can appear more than once on a page).
  const activeSections = activePageObj?.sections ?? [];

  const addSection = (sectionKey: string) => {
    const def = pageSections.find((p) => p.key === sectionKey);
    const variant = def?.variants[0]?.key ?? "";
    updateActivePage({ sections: [...activeSections, { id: uid(), section: sectionKey, variant }] });
    setFocused({ kind: "section", section: sectionKey, variant });
  };

  const removeSection = (id: string) =>
    updateActivePage({ sections: activeSections.filter((s) => s.id !== id) });

  const duplicateSection = (id: string) => {
    const i = activeSections.findIndex((s) => s.id === id);
    if (i < 0) return;
    updateActivePage({ sections: [...activeSections.slice(0, i + 1), { ...activeSections[i], id: uid() }, ...activeSections.slice(i + 1)] });
  };

  const setSectionVariantById = (id: string, variant: string) => {
    const item = activeSections.find((s) => s.id === id);
    updateActivePage({ sections: activeSections.map((s) => (s.id === id ? { ...s, variant } : s)) });
    if (item) setFocused({ kind: "section", section: item.section, variant });
  };

  const moveSection = (id: string, dir: -1 | 1) => {
    const i = activeSections.findIndex((s) => s.id === id);
    const j = i + dir;
    if (i < 0 || j < 0 || j >= activeSections.length) return;
    updateActivePage({ sections: arrayMove(activeSections, i, j) });
  };

  const onSectionDragEnd = (e: DragEndEvent) => {
    const { active, over } = e;
    if (!over || active.id === over.id) return;
    const from = activeSections.findIndex((s) => s.id === active.id);
    const to = activeSections.findIndex((s) => s.id === over.id);
    if (from < 0 || to < 0) return;
    updateActivePage({ sections: arrayMove(activeSections, from, to) });
  };

  const canNext = (() => {
    switch (step) {
      case 0: return form.prospect_name.trim().length > 0;
      case 1: return Boolean(form.business_type);
      case 2: return Boolean(form.website_type);
      case 3: return Boolean(form.sections.header) && form.sections.pages.length > 0;
      case 4: return Boolean(form.brand_kit);
      default: return true;
    }
  })();

  const save = () => {
    if (!form.business_type || !form.website_type || !form.brand_kit) return;
    create.mutate(
      {
        prospect_name: form.prospect_name.trim(),
        prospect_contact: form.prospect_contact.trim() || undefined,
        notes: form.notes.trim() || undefined,
        business_type: form.business_type,
        website_type: form.website_type,
        brand_kit: form.brand_kit,
        typography: form.typography || undefined,
        type_scale: form.type_scale || undefined,
        design_style: form.design_style || undefined,
        heading_style: form.heading_style || undefined,
        radius: form.radius || undefined,
        cta_hover: form.cta_hover || undefined,
        logo: form.logo || undefined,
        sections: form.sections,
      },
      {
        onSuccess: () => {
          notify.success("Website selection saved.");
          router.push("/kiosk/leads");
        },
        onError: () => notify.error("Couldn’t save — please try again."),
      },
    );
  };

  const slot = (name: string) => (catalog?.sections ?? []).find((s) => s.slot === name);
  const pageSections = catalog?.page_sections ?? [];
  const theme = resolveTheme(catalog, form);

  const siteInput: SiteInput = {
    businessName: form.prospect_name,
    websiteType: form.website_type,
    header: form.sections.header,
    footer: form.sections.footer,
    pages: form.sections.pages,
    logo: form.logo,
    // The whole-site preview opens on the page being edited.
    activeSlug: activePageObj?.slug ?? null,
  };

  // "Per-section" preview — just the last-touched piece on a lone Home page, so a
  // fresh pick shows immediately without scrolling the whole assembled site.
  const focusedInput = (fo: Focused): SiteInput => {
    const base = { businessName: form.prospect_name, websiteType: form.website_type, logo: form.logo };
    const onePage = (hero: string | null, sections: KioskPageChoice[]): SiteInput => ({
      ...base,
      header: null,
      footer: null,
      pages: [{ name: "Home", slug: "home", hero, sections }],
    });
    if (fo.kind === "header") return { ...base, header: fo.variant, footer: null, pages: [{ name: "Home", slug: "home", hero: null, sections: [] }] };
    if (fo.kind === "footer") return { ...base, header: null, footer: fo.variant, pages: [{ name: "Home", slug: "home", hero: null, sections: [] }] };
    if (fo.kind === "hero") return onePage(fo.variant, []);
    return onePage(null, [{ section: fo.section, variant: fo.variant }]);
  };

  const previewInput = previewMode === "section" && focused ? focusedInput(focused) : siteInput;

  // The live side-panel preview reflects EVERY design pick (typography, size,
  // style, corners…) live — even before a colour kit is chosen — falling back to
  // a neutral palette/fonts until those are picked.
  const previewKit = catalog?.brand_kits.find((k) => k.key === form.brand_kit);
  const previewTypo = catalog?.typographies.find((t) => t.key === form.typography);
  const previewTheme: KioskTheme = {
    palette: previewKit?.palette ?? QUICKVIEW_THEME.palette,
    fonts: previewTypo
      ? { heading: previewTypo.heading, body: previewTypo.body }
      : previewKit?.fonts ?? QUICKVIEW_THEME.fonts,
    scale: form.type_scale || "standard",
    style: form.design_style || "clean",
    headingStyle: form.heading_style || "standard",
    radius: form.radius || "soft",
    ctaHover: form.cta_hover || "wipe",
    dark: previewKit?.dark ?? false,
  };

  return (
    <>
      <PageHeader title="New Website" subtitle="Pick the layout for each part — you’ll capture this as a lead at the end." />

      {/* Stepper */}
      <div className="mt-6 flex flex-wrap items-center gap-2">
        {STEPS.map((label, i) => (
          <div key={label} className="flex items-center gap-2">
            <span
              className={
                "flex size-6 items-center justify-center rounded-full text-caption font-bold " +
                (i < step ? "bg-brand text-brand-foreground" : i === step ? "bg-teal-ink text-white" : "bg-muted text-muted-foreground")
              }
            >
              {i < step ? <Check className="size-3.5" /> : i + 1}
            </span>
            <span className={"text-caption " + (i === step ? "font-semibold text-foreground" : "text-muted-foreground")}>{label}</span>
            {i < STEPS.length - 1 ? <span className="mx-1 h-px w-5 bg-border" /> : null}
          </div>
        ))}
      </div>

      <div className="mt-6 rounded-card border border-border bg-card p-6">
        {/* Step 0 — Prospect */}
        {step === 0 ? (
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="grid gap-1.5">
              <span className="text-sm font-medium">Business / prospect name *</span>
              <input value={form.prospect_name} onChange={(e) => set("prospect_name", e.target.value)} placeholder="e.g. Ana's Bakery" className={inputCls} />
            </label>
            <label className="grid gap-1.5">
              <span className="text-sm font-medium">Contact (email / phone)</span>
              <input value={form.prospect_contact} onChange={(e) => set("prospect_contact", e.target.value)} placeholder="ana@business.ph" className={inputCls} />
            </label>
            <label className="grid gap-1.5 sm:col-span-2">
              <span className="text-sm font-medium">Notes</span>
              <textarea value={form.notes} onChange={(e) => set("notes", e.target.value)} placeholder="What does the client want?" rows={3} className="w-full rounded-lg border border-input bg-transparent px-2.5 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50" />
            </label>
          </div>
        ) : null}

        {/* Step 1 — Business type */}
        {step === 1 ? (
          <OptionGrid options={(catalog?.business_types ?? []).map((b) => ({ key: b.key, label: b.label, sub: b.description }))} selected={form.business_type} onPick={(k) => set("business_type", k)} />
        ) : null}

        {/* Step 2 — Website type */}
        {step === 2 ? (
          <OptionGrid options={(catalog?.website_types ?? []).map((w) => ({ key: w.key, label: w.label, sub: w.description }))} selected={form.website_type} onPick={(k) => set("website_type", k)} />
        ) : null}

        {/* Step 3 — Sections: site-wide header/footer + per-page content + live preview */}
        {step === 3 ? (
          <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(320px,42%)]">
            <div className="space-y-6">
            {(["header", "footer"] as const).map((name) => {
              const def = slot(name);
              if (!def) return null;
              const required = name === "header";
              return (
                <div key={name}>
                  <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
                    {def.label}{required ? " *" : ""} <span className="font-normal normal-case text-muted-foreground/70">· site-wide</span>
                  </p>
                  <div className="mt-2 grid gap-2 sm:grid-cols-4">
                    {def.variants.map((v) => (
                      <VariantOption
                        key={v.key}
                        label={v.label}
                        active={form.sections[name] === v.key}
                        onPick={() => {
                          setSlot(name, v.key);
                          setFocused({ kind: name, variant: v.key });
                        }}
                      />
                    ))}
                  </div>
                </div>
              );
            })}

            {/* Pages — tabs + add/remove (each page has its own hero + sections) */}
            <div>
              <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">Pages</p>
              <div className="mt-2 flex flex-wrap items-center gap-2">
                {pages.map((p, i) => (
                  <div
                    key={p.id ?? i}
                    className={"flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm " + (i === activeIdx ? "border-brand bg-brand-subtle/30 text-foreground" : "border-border text-muted-foreground")}
                  >
                    <button type="button" onClick={() => setActivePage(i)} className="font-semibold">{p.name || "Untitled"}</button>
                    {pages.length > 1 ? (
                      <button type="button" onClick={() => removePage(i)} aria-label={`Remove ${p.name}`} className="text-base leading-none text-muted-foreground/60 hover:text-destructive">×</button>
                    ) : null}
                  </div>
                ))}
                <select
                  aria-label="Add a page"
                  value=""
                  onChange={(e) => { if (e.target.value) { addPage(e.target.value); e.currentTarget.value = ""; } }}
                  className={inputCls + " h-8 w-auto cursor-pointer"}
                >
                  <option value="">+ Add page</option>
                  {(catalog?.page_blueprints ?? []).map((b) => (
                    <option key={b.key} value={b.key}>{b.name}</option>
                  ))}
                </select>
              </div>
            </div>

            {/* Active page editor */}
            {activePageObj ? (
              <div className="space-y-5 rounded-card border border-border p-4">
                <div>
                  <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">Page name</p>
                  <input
                    className={inputCls + " mt-1 max-w-xs"}
                    value={activePageObj.name}
                    onChange={(e) => updateActivePage({ name: e.target.value })}
                    placeholder="Page name"
                  />
                </div>

                <div>
                  <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">Hero <span className="font-normal normal-case text-muted-foreground/70">(optional)</span></p>
                  <div className="mt-2 grid gap-2 sm:grid-cols-4">
                    <VariantOption label="None" active={!activePageObj.hero} onPick={() => updateActivePage({ hero: null })} />
                    {(slot("hero")?.variants ?? []).map((v) => (
                      <VariantOption key={v.key} label={v.label} active={activePageObj.hero === v.key} onPick={() => setPageHero(v.key)} />
                    ))}
                  </div>
                </div>

                <div>
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
                      Content sections <span className="font-normal normal-case text-muted-foreground/70">· drag to reorder</span>
                    </p>
                    <select
                      aria-label="Add a section"
                      value=""
                      onChange={(e) => { if (e.target.value) { addSection(e.target.value); e.currentTarget.value = ""; } }}
                      className={inputCls + " h-8 w-auto cursor-pointer"}
                    >
                      <option value="">+ Add section</option>
                      {pageSections.map((ps) => (
                        <option key={ps.key} value={ps.key}>{ps.label}</option>
                      ))}
                    </select>
                  </div>
                  <div className="mt-2 space-y-2">
                    {activeSections.length === 0 ? (
                      <p className="rounded-card border border-dashed border-border p-4 text-center text-caption text-muted-foreground">
                        No sections yet — add one above.
                      </p>
                    ) : (
                      <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onSectionDragEnd}>
                        <SortableContext items={activeSections.map((s) => s.id ?? "")} strategy={verticalListSortingStrategy}>
                          {activeSections.map((s, i, arr) => (
                            <SortableSectionCard
                              key={s.id}
                              item={s}
                              def={pageSections.find((p) => p.key === s.section)}
                              onVariant={(v) => setSectionVariantById(s.id ?? "", v)}
                              onDuplicate={() => duplicateSection(s.id ?? "")}
                              onRemove={() => removeSection(s.id ?? "")}
                              onMove={(dir) => moveSection(s.id ?? "", dir)}
                              canUp={i > 0}
                              canDown={i < arr.length - 1}
                            />
                          ))}
                        </SortableContext>
                      </DndContext>
                    )}
                  </div>
                </div>
              </div>
            ) : null}
            </div>
            <LivePreview input={previewInput} theme={previewTheme} mode={previewMode} onModeChange={setPreviewMode} />
          </div>
        ) : null}

        {/* Step 4 — Design (colour · typography · size · style) + live preview */}
        {step === 4 ? (
          <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(320px,42%)]">
            <div className="space-y-6">
            <div>
              <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
                Logo <span className="font-normal normal-case text-muted-foreground/70">(optional)</span>
              </p>
              <div className="mt-2 flex items-center gap-4">
                <div className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-card border border-border bg-muted/40">
                  {form.logo ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={form.logo} alt="Logo preview" className="max-h-full max-w-full object-contain p-1" />
                  ) : (
                    <ImageIcon className="size-6 text-muted-foreground" />
                  )}
                </div>
                <div className="flex flex-col items-start gap-1.5">
                  <label className="inline-flex w-fit cursor-pointer items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-sm font-semibold text-foreground transition-colors hover:border-brand hover:bg-muted/40">
                    <Upload className="size-4" />
                    {form.logo ? "Replace logo" : "Upload logo"}
                    <input
                      type="file"
                      accept="image/png,image/jpeg,image/svg+xml,image/webp"
                      className="hidden"
                      onChange={(e) => {
                        onLogoFile(e.target.files?.[0] ?? null);
                        e.target.value = "";
                      }}
                    />
                  </label>
                  {form.logo ? (
                    <button type="button" onClick={() => set("logo", null)} className="w-fit text-xs font-semibold text-danger hover:underline">
                      Remove logo
                    </button>
                  ) : (
                    <p className="text-xs text-muted-foreground">PNG, JPG, SVG or WebP · under 500KB · replaces the text logo in the header &amp; footer.</p>
                  )}
                </div>
              </div>
            </div>
            <div>
              <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">Colour theme *</p>
              <div className="mt-2 grid gap-3 sm:grid-cols-3">
                {(catalog?.brand_kits ?? []).map((kit) => {
                  const active = form.brand_kit === kit.key;
                  return (
                    <button key={kit.key} type="button" onClick={() => set("brand_kit", kit.key)} className={"rounded-card border p-3 text-left transition-colors " + (active ? "border-brand bg-brand-subtle/40" : "border-border hover:border-brand")}>
                      <div className="flex gap-1.5">
                        {[kit.palette.primary, kit.palette.accent, kit.palette.bg, kit.palette.text].map((c) => (
                          <span key={c} className="size-5 rounded-full border border-border" style={{ backgroundColor: c }} />
                        ))}
                      </div>
                      <p className="mt-2 text-sm font-bold text-foreground">{kit.label}</p>
                    </button>
                  );
                })}
              </div>
            </div>

            <div>
              <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">Typography</p>
              <OptionGrid
                options={[
                  { key: "", label: "Theme default", sub: "Use the colour theme's own fonts" },
                  ...(catalog?.typographies ?? []).map((t) => ({
                    key: t.key,
                    label: t.label,
                    sub: `${t.heading} · ${t.body}`,
                    font: t.heading,
                  })),
                ]}
                selected={form.typography ?? ""}
                onPick={(k) => set("typography", k || null)}
              />
            </div>

            <div>
              <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">Size</p>
              <OptionGrid
                options={(catalog?.type_scales ?? []).map((s) => ({ key: s.key, label: s.label, sub: s.description }))}
                selected={form.type_scale}
                onPick={(k) => set("type_scale", k)}
              />
            </div>

            <div>
              <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">Style</p>
              <OptionGrid
                options={(catalog?.styles ?? []).map((s) => ({ key: s.key, label: s.label, sub: s.description }))}
                selected={form.design_style}
                onPick={(k) => set("design_style", k)}
              />
            </div>

            <div>
              <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">Headings</p>
              <OptionGrid
                options={(catalog?.heading_styles ?? []).map((s) => ({ key: s.key, label: s.label, sub: s.description }))}
                selected={form.heading_style}
                onPick={(k) => set("heading_style", k)}
              />
            </div>

            <div>
              <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">Corners</p>
              <OptionGrid
                options={(catalog?.radii ?? []).map((s) => ({ key: s.key, label: s.label, sub: s.description }))}
                selected={form.radius}
                onPick={(k) => set("radius", k)}
              />
            </div>

            <div>
              <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">Button hover</p>
              <OptionGrid
                options={(catalog?.cta_hovers ?? []).map((s) => ({ key: s.key, label: s.label, sub: s.description }))}
                selected={form.cta_hover}
                onPick={(k) => set("cta_hover", k)}
              />
            </div>
            </div>
            <LivePreview input={previewInput} theme={previewTheme} mode={previewMode} onModeChange={setPreviewMode} />
          </div>
        ) : null}

        {/* Step 5 — Review + live preview */}
        {step === 5 ? (
          <div>
            <div className="flex flex-wrap items-center justify-between gap-3">
              <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">Live preview</p>
              {theme ? <ExportButton input={siteInput} theme={theme} /> : null}
            </div>
            {theme ? (
              <div className="mt-2">
                <PreviewFrame>
                  <KioskPreview input={siteInput} theme={theme} />
                </PreviewFrame>
              </div>
            ) : null}
            <p className="mt-3 text-caption text-muted-foreground">
              Scroll the preview — the reveal + parallax animations run live, and “Save lead” keeps this exact configuration.
            </p>
          </div>
        ) : null}
      </div>

      {/* Nav */}
      <div className="mt-5 flex items-center justify-between">
        <button type="button" disabled={step === 0} onClick={() => setStep((s) => Math.max(0, s - 1))} className="inline-flex items-center gap-1.5 rounded-lg border border-border px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted/40 disabled:opacity-40">
          <ArrowLeft className="size-4" /> Back
        </button>

        {step < STEPS.length - 1 ? (
          <button type="button" disabled={!canNext} onClick={() => { if (step === 2) seedPagesIfEmpty(); setStep((s) => s + 1); }} className="inline-flex items-center gap-1.5 rounded-lg bg-brand px-5 py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90 disabled:opacity-50">
            Next <ArrowRight className="size-4" />
          </button>
        ) : (
          <button type="button" disabled={create.isPending} onClick={save} className="inline-flex items-center gap-1.5 rounded-lg bg-brand px-5 py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90 disabled:opacity-50">
            {create.isPending ? "Saving…" : "Save lead"}
          </button>
        )}
      </div>
    </>
  );
}

function OptionGrid({
  options,
  selected,
  onPick,
}: {
  options: { key: string; label: string; sub?: string; font?: string }[];
  selected: string | null;
  onPick: (key: string) => void;
}) {
  return (
    <div className="grid gap-2 sm:grid-cols-3">
      {options.map((o) => (
        <button key={o.key} type="button" onClick={() => onPick(o.key)} className={"rounded-card border p-4 text-left transition-colors " + (selected === o.key ? "border-brand bg-brand-subtle/40" : "border-border hover:border-brand")}>
          {/* Render typography labels in their actual font (live font preview). */}
          <p className="text-sm font-semibold text-foreground" style={o.font ? { fontFamily: `'${o.font}', system-ui, sans-serif` } : undefined}>{o.label}</p>
          {o.sub ? <p className="mt-0.5 text-caption text-muted-foreground">{o.sub}</p> : null}
        </button>
      ))}
    </div>
  );
}

/** A single layout option (selection only — the live panel shows the result). */
function VariantOption({ label, active, onPick }: { label: string; active: boolean; onPick: () => void }) {
  return (
    <button
      type="button"
      onClick={onPick}
      className={"w-full rounded-lg border px-3 py-2 text-left text-sm transition-colors " + (active ? "border-brand bg-brand-subtle/40 font-semibold text-foreground" : "border-border text-foreground hover:border-brand")}
    >
      {label}
    </button>
  );
}

/** One draggable content-section card on a page: drag handle, move up/down,
 * duplicate, remove, and its layout-variant picker. */
function SortableSectionCard({
  item, def, onVariant, onDuplicate, onRemove, onMove, canUp, canDown,
}: {
  item: KioskPageChoice;
  def?: KioskPageSection;
  onVariant: (variant: string) => void;
  onDuplicate: () => void;
  onRemove: () => void;
  onMove: (dir: -1 | 1) => void;
  canUp: boolean;
  canDown: boolean;
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: item.id ?? "" });
  const style = { transform: CSS.Transform.toString(transform), transition, opacity: isDragging ? 0.6 : 1 };
  return (
    <div ref={setNodeRef} style={style} className="rounded-card border border-brand bg-brand-subtle/20 p-3">
      <div className="flex items-center gap-2">
        <button
          type="button"
          {...attributes}
          {...listeners}
          aria-label="Drag to reorder"
          className="cursor-grab touch-none text-muted-foreground/60 hover:text-foreground active:cursor-grabbing"
        >
          <GripVertical className="size-4" />
        </button>
        <span className="text-sm font-semibold text-foreground">{def?.label ?? item.section}</span>
        <div className="ml-auto flex items-center gap-0.5">
          <button type="button" onClick={() => onMove(-1)} disabled={!canUp} aria-label="Move up" className="rounded p-1 text-muted-foreground/70 hover:text-foreground disabled:opacity-30"><ChevronUp className="size-4" /></button>
          <button type="button" onClick={() => onMove(1)} disabled={!canDown} aria-label="Move down" className="rounded p-1 text-muted-foreground/70 hover:text-foreground disabled:opacity-30"><ChevronDown className="size-4" /></button>
          <button type="button" onClick={onDuplicate} aria-label="Duplicate" className="rounded p-1 text-muted-foreground/70 hover:text-foreground"><Copy className="size-4" /></button>
          <button type="button" onClick={onRemove} aria-label="Remove" className="rounded p-1 text-muted-foreground/70 hover:text-destructive"><Trash2 className="size-4" /></button>
        </div>
      </div>
      <div className="mt-2 grid gap-2 pl-6 sm:grid-cols-3">
        {(def?.variants ?? []).map((v) => (
          <VariantOption key={v.key} label={v.label} active={item.variant === v.key} onPick={() => onVariant(v.key)} />
        ))}
      </div>
    </div>
  );
}

const DEVICE_W = { mobile: 390, tablet: 834, desktop: 1280 } as const;
type Device = keyof typeof DEVICE_W;

/** The sticky right-side live preview — whole site or focused section, at a
 * chosen device width (scaled to fit the narrow panel). */
function LivePreview({
  input,
  theme,
  mode,
  onModeChange,
}: {
  input: SiteInput;
  theme: KioskTheme;
  mode: "whole" | "section";
  onModeChange: (m: "whole" | "section") => void;
}) {
  const [device, setDevice] = useState<Device>("desktop");
  const wrapRef = useRef<HTMLDivElement>(null);
  const [wrapW, setWrapW] = useState(0);

  useEffect(() => {
    const el = wrapRef.current;
    if (!el) return;
    const update = () => setWrapW(el.clientWidth);
    update();
    const ro = new ResizeObserver(update);
    ro.observe(el);
    return () => ro.disconnect();
  }, []);

  const deviceW = DEVICE_W[device];
  const displayH = 480; // on-screen preview height (px)
  const scale = wrapW > 0 ? Math.min(1, wrapW / deviceW) : 1;
  const boxW = Math.round(deviceW * scale);
  const frameH = Math.round(displayH / scale); // logical height → fills displayH after scaling

  const seg = (active: boolean) =>
    "rounded-md px-2.5 py-1 text-caption font-semibold transition-colors " +
    (active ? "bg-brand text-brand-foreground" : "text-muted-foreground hover:text-foreground");

  return (
    <div className="lg:sticky lg:top-6 lg:self-start">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">Live preview</p>
        <div className="flex flex-wrap items-center gap-2">
          <div className="flex rounded-lg border border-border p-0.5">
            <button type="button" onClick={() => onModeChange("whole")} className={seg(mode === "whole")}>Whole</button>
            <button type="button" onClick={() => onModeChange("section")} className={seg(mode === "section")}>Section</button>
          </div>
          <div className="flex rounded-lg border border-border p-0.5">
            {(["mobile", "tablet", "desktop"] as const).map((d) => (
              <button key={d} type="button" title={d} onClick={() => setDevice(d)} className={seg(device === d) + " !px-2"}>
                {d === "mobile" ? <Smartphone className="size-3.5" /> : d === "tablet" ? <Tablet className="size-3.5" /> : <Monitor className="size-3.5" />}
              </button>
            ))}
          </div>
        </div>
      </div>
      <div ref={wrapRef}>
        <PreviewFrame>
          <div style={{ width: boxW, height: displayH, margin: "0 auto", overflow: "hidden" }}>
            <div style={{ width: deviceW, height: frameH, transform: `scale(${scale})`, transformOrigin: "top left" }}>
              <KioskPreview input={input} theme={theme} height={`${frameH}px`} />
            </div>
          </div>
        </PreviewFrame>
      </div>
    </div>
  );
}
