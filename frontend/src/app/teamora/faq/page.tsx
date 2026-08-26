"use client";

import { useMemo, useState } from "react";
import { Search, ChevronDown, HelpCircle, Pin } from "lucide-react";

import { cn } from "@/lib/utils";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/modules/hr/components/page-header";
import { StatusFilter, type StatusFilterOption } from "@/core/components/status-filter";
import { useDebouncedValue } from "@/core/use-debounced-value";
import { useFaqs, useViewFaq } from "@/modules/hr/hooks/use-faqs";
import { FAQ_CATEGORIES, faqCategoryLabel, type Faq } from "@/modules/hr/types/faq";

export default function FaqPage() {
  const [search, setSearch] = useState("");
  const [category, setCategory] = useState("all");
  // The search runs SERVER-side, so an undebounced box is one request per keystroke —
  // typing "leave" fired five. The input still binds the raw value, so it stays responsive.
  const debouncedSearch = useDebouncedValue(search.trim(), 250);
  const { data: faqs, isLoading } = useFaqs(debouncedSearch || undefined);
  const viewMut = useViewFaq();

  const [openId, setOpenId] = useState<number | null>(null);
  const [viewed, setViewed] = useState<Set<number>>(new Set());

  const filtered = (faqs ?? []).filter((f) => category === "all" || f.category === category);

  const categoryOptions = useMemo<StatusFilterOption[]>(
    () => [{ value: "all", label: "All" }, ...FAQ_CATEGORIES.map((c) => ({ value: c.value, label: c.label }))],
    [],
  );

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
      <div className="mt-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div className="relative w-full lg:max-w-xl">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search FAQs…"
            className="h-11 pl-9"
          />
        </div>
        <StatusFilter options={categoryOptions} value={category} onChange={setCategory} />
      </div>

      <div className="mt-6">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : filtered.length === 0 ? (
          <p className="text-sm text-muted-foreground">No FAQs match your search.</p>
        ) : (
          filtered.map((f) => {
            const open = openId === f.id;
            return (
              <div key={f.id} className="border-b border-border last:border-0">
                <button
                  type="button"
                  onClick={() => toggle(f)}
                  className="flex w-full items-center justify-between gap-3 py-4 text-left"
                >
                  <span className="flex min-w-0 flex-1 items-center gap-4">
                    <span className="h-14 w-1.5 shrink-0 rounded-[3px] bg-brand" />
                    <span className="flex min-w-0 flex-1 items-center gap-3">
                      <HelpCircle className="size-5 shrink-0 text-brand" />
                      {f.is_pinned && <Pin className="size-4 shrink-0 text-brand" />}
                      <span className="min-w-0 flex-1 truncate text-body font-bold text-foreground">
                        {f.question}
                      </span>
                    </span>
                  </span>
                  <span className="flex shrink-0 items-center gap-3">
                    <span className="rounded-full bg-brand-subtle px-2.5 py-1 text-sm font-bold text-brand">
                      {faqCategoryLabel(f.category)}
                    </span>
                    <ChevronDown
                      className={cn(
                        "size-5 text-muted-foreground transition-transform",
                        open && "rotate-180",
                      )}
                    />
                  </span>
                </button>
                {open && (
                  <div className="space-y-2.5 pb-4 pl-[22px]">
                    <p className="whitespace-pre-wrap text-body text-muted-foreground">{f.answer}</p>
                    {f.tags.length > 0 && (
                      <div className="flex flex-wrap gap-2">
                        {f.tags.map((t) => (
                          <span
                            key={t}
                            className="rounded-full bg-brand-subtle px-2.5 py-1 text-sm font-bold text-brand"
                          >
                            #{t}
                          </span>
                        ))}
                      </div>
                    )}
                  </div>
                )}
              </div>
            );
          })
        )}
      </div>
    </div>
  );
}
