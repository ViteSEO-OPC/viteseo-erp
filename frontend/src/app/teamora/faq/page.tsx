"use client";

import { useState } from "react";
import { Search, ChevronDown, Pin } from "lucide-react";

import { cn } from "@/lib/utils";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/modules/hr/components/page-header";
import { useFaqs, useViewFaq } from "@/modules/hr/hooks/use-faqs";
import { FAQ_CATEGORIES, faqCategoryLabel, type Faq } from "@/modules/hr/types/faq";

export default function FaqPage() {
  const [search, setSearch] = useState("");
  const [category, setCategory] = useState("all");
  const { data: faqs, isLoading } = useFaqs(search.trim() || undefined);
  const viewMut = useViewFaq();

  const [openId, setOpenId] = useState<number | null>(null);
  const [viewed, setViewed] = useState<Set<number>>(new Set());

  const filtered = (faqs ?? []).filter((f) => category === "all" || f.category === category);

  function toggle(f: Faq) {
    const next = openId === f.id ? null : f.id;
    setOpenId(next);
    // Count a view the first time an entry is opened this session.
    if (next === f.id && !viewed.has(f.id)) {
      viewMut.mutate(f.id);
      setViewed((s) => new Set(s).add(f.id));
    }
  }

  return (
    <div className="p-4 sm:p-8">
      <PageHeader title="FAQ" subtitle="Answers to common questions about payroll, leave, attendance and more." />

      {/* Search + category filter */}
      <div className="mt-6 flex flex-wrap gap-2">
        <div className="relative w-full max-w-sm">
          <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input className="pl-8" placeholder="Search FAQs…" value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
        <div className="flex flex-wrap gap-1.5">
          <Chip active={category === "all"} onClick={() => setCategory("all")}>All</Chip>
          {FAQ_CATEGORIES.map((c) => (
            <Chip key={c.value} active={category === c.value} onClick={() => setCategory(c.value)}>
              {c.label}
            </Chip>
          ))}
        </div>
      </div>

      <div className="mt-6 space-y-2">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : filtered.length === 0 ? (
          <p className="text-sm text-muted-foreground">No FAQs match your search.</p>
        ) : (
          filtered.map((f) => (
            <div key={f.id} className="overflow-hidden rounded-card border border-border bg-card">
              <button
                type="button"
                onClick={() => toggle(f)}
                className="flex w-full items-center justify-between gap-3 px-4 py-3.5 text-left"
              >
                <span className="flex items-center gap-2 font-medium text-foreground">
                  {f.is_pinned && <Pin className="size-3.5 text-brand" />}
                  {f.question}
                </span>
                <span className="flex shrink-0 items-center gap-2">
                  <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                    {faqCategoryLabel(f.category)}
                  </span>
                  <ChevronDown className={cn("size-4 text-muted-foreground transition-transform", openId === f.id && "rotate-180")} />
                </span>
              </button>
              {openId === f.id && (
                <div className="border-t border-border px-4 py-3.5">
                  <p className="whitespace-pre-wrap text-sm text-foreground">{f.answer}</p>
                  {f.tags.length > 0 && (
                    <div className="mt-3 flex flex-wrap gap-1.5">
                      {f.tags.map((t) => (
                        <span key={t} className="rounded-full bg-brand-subtle px-2 py-0.5 text-xs text-dark-teal">#{t}</span>
                      ))}
                    </div>
                  )}
                </div>
              )}
            </div>
          ))
        )}
      </div>
    </div>
  );
}

function Chip({
  active,
  onClick,
  children,
}: {
  active: boolean;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        "rounded-full border px-3 py-1 text-sm font-medium transition-colors",
        active ? "border-brand bg-brand text-brand-foreground" : "border-input text-muted-foreground hover:bg-muted",
      )}
    >
      {children}
    </button>
  );
}
