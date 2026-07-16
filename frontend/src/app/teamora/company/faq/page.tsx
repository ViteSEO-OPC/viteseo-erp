"use client";

import { useState } from "react";
import { Plus, Pin, Search, ChevronDown } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/core/components/page-header";
import { DataTable, type Column } from "@/core/components/data-table";
import { StatusBadge } from "@/core/components/status-badge";
import { formatDate } from "@/core/lib/format";
import { FaqFormDialog } from "@/modules/hr/components/faq-form-dialog";
import {
  useFaqs,
  useDeleteFaq,
  useSetFaqStatus,
  usePinFaq,
} from "@/modules/hr/hooks/use-faqs";
import { FAQ_CATEGORIES, faqCategoryLabel, type Faq } from "@/modules/hr/types/faq";

const selectClass =
  "h-9 rounded-lg border border-input bg-transparent px-2.5 text-sm text-foreground outline-none focus-visible:border-ring";

// Category → token colors for the pill badge.
const CATEGORY_STYLE: Record<string, string> = {
  leave: "bg-success-subtle text-success",
  payroll: "bg-info-subtle text-info",
  attendance: "bg-warning-subtle text-warning",
  benefits: "bg-brand-subtle text-dark-teal",
  general: "bg-muted text-muted-foreground",
};

function CategoryBadge({ category }: { category: string }) {
  return (
    <span
      className={cn(
        "inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold",
        CATEGORY_STYLE[category] ?? "bg-muted text-muted-foreground",
      )}
    >
      {faqCategoryLabel(category)}
    </span>
  );
}

export default function FaqAdminPage() {
  const [search, setSearch] = useState("");
  const [category, setCategory] = useState("all");
  const [status, setStatus] = useState("all");

  const { data: faqs, isLoading, isError } = useFaqs(search.trim() || undefined);
  const deleteMut = useDeleteFaq();
  const statusMut = useSetFaqStatus();
  const pinMut = usePinFaq();

  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<Faq | null>(null);
  const [openId, setOpenId] = useState<number | null>(null);

  async function run(fn: () => Promise<unknown>, ok: string) {
    try {
      await fn();
      toast.success(ok);
    } catch {
      toast.error("Something went wrong.");
    }
  }

  const byCategory = (faqs ?? []).filter((f) => category === "all" || f.category === category);
  const published = byCategory.filter((f) => f.status === "published");
  const tableRows = byCategory.filter((f) => status === "all" || f.status === status);
  const rowNumber = new Map(tableRows.map((f, i) => [f.id, i + 1]));

  const columns: Column<Faq>[] = [
    { header: "#", cell: (f) => <span className="text-muted-foreground">{rowNumber.get(f.id)}</span>, className: "w-10" },
    { header: "Question", cell: (f) => <span className="font-medium text-foreground">{f.question}</span> },
    { header: "Category", cell: (f) => <CategoryBadge category={f.category} /> },
    { header: "Status", cell: (f) => <StatusBadge status={f.status} /> },
    { header: "Views", cell: (f) => f.view_count },
    { header: "Last Updated", cell: (f) => <span className="text-muted-foreground">{formatDate(f.updated_at)}</span> },
    { header: "Updated By", cell: (f) => <span className="text-muted-foreground">{f.updated_by ?? "—"}</span> },
    {
      header: "Actions",
      align: "right",
      cell: (f) => (
        <div className="flex justify-end gap-3 text-sm font-medium">
          <ActionLink className="text-foreground" onClick={() => { setEditing(f); setDialogOpen(true); }}>
            Edit
          </ActionLink>

          {/* Status transition — contextual */}
          {f.status === "published" ? (
            <ActionLink className="text-danger" onClick={() => run(() => statusMut.mutateAsync({ id: f.id, action: "archive" }), "Archived")}>
              Archive
            </ActionLink>
          ) : (
            <ActionLink className="text-success" onClick={() => run(() => statusMut.mutateAsync({ id: f.id, action: "publish" }), f.status === "archived" ? "Restored" : "Published")}>
              {f.status === "archived" ? "Restore" : "Publish"}
            </ActionLink>
          )}

          {/* Always available */}
          <ActionLink className="text-brand" onClick={() => run(() => pinMut.mutateAsync(f.id), f.is_pinned ? "Unpinned" : "Pinned")}>
            {f.is_pinned ? "Unpin" : "Pin"}
          </ActionLink>
          <ActionLink className="text-danger" onClick={() => { if (confirm("Delete this FAQ?")) run(() => deleteMut.mutateAsync(f.id), "Deleted"); }}>
            Delete
          </ActionLink>
        </div>
      ),
    },
  ];

  return (
    <div className="p-8">
      <PageHeader
        title="FAQ"
        subtitle="Self-service FAQ library for common employee questions. Employees have read-only access; only HR/Admin can create or edit entries."
      />

      {/* Toolbar: search + filters + create */}
      <div className="mt-6 flex flex-wrap items-center gap-3">
        <div className="relative min-w-[240px] flex-1">
          <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            className="pl-8"
            placeholder="Search FAQs by question, tag, or category…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        <select className={selectClass} value={category} onChange={(e) => setCategory(e.target.value)}>
          <option value="all">All Categories</option>
          {FAQ_CATEGORIES.map((c) => (
            <option key={c.value} value={c.value}>{c.label}</option>
          ))}
        </select>
        <select className={selectClass} value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="all">All statuses</option>
          <option value="published">Published</option>
          <option value="draft">Draft</option>
          <option value="archived">Archived</option>
        </select>
        <Button onClick={() => { setEditing(null); setDialogOpen(true); }}>
          <Plus />
          New FAQ
        </Button>
      </div>

      {/* Published FAQs — accordion reading view */}
      <section className="mt-8">
        <div className="flex items-center justify-between">
          <h2 className="text-h3 font-bold text-foreground">Published FAQs</h2>
          <span className="rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
            {published.length} {published.length === 1 ? "entry" : "entries"}
          </span>
        </div>

        <div className="mt-4 space-y-3">
          {isLoading ? (
            <p className="text-sm text-muted-foreground">Loading FAQs…</p>
          ) : published.length === 0 ? (
            <p className="text-sm text-muted-foreground">No published FAQs match your filters.</p>
          ) : (
            published.map((f) => (
              <div key={f.id} className="rounded-card border border-border bg-card p-4">
                <button
                  type="button"
                  onClick={() => setOpenId(openId === f.id ? null : f.id)}
                  className="flex w-full items-start justify-between gap-3 text-left"
                >
                  <div className="min-w-0">
                    <p className="flex items-center gap-1.5 font-semibold text-foreground">
                      {f.is_pinned && <Pin className="size-3.5 shrink-0 text-brand" />}
                      {f.question}
                    </p>
                    <div className="mt-1.5 flex flex-wrap items-center gap-2">
                      <CategoryBadge category={f.category} />
                      <StatusBadge status={f.status} />
                    </div>
                  </div>
                  <div className="flex shrink-0 items-center gap-3">
                    <span className="hidden text-caption text-muted-foreground lg:block">
                      Last updated: {formatDate(f.updated_at)} by {f.updated_by ?? "—"}
                    </span>
                    <ChevronDown className={cn("size-4 text-muted-foreground transition-transform", openId === f.id && "rotate-180")} />
                  </div>
                </button>

                {openId === f.id ? (
                  <div className="mt-3 border-t border-border pt-3">
                    <p className="whitespace-pre-wrap text-sm text-foreground">{f.answer}</p>
                    {f.tags.length > 0 && (
                      <div className="mt-3 flex flex-wrap gap-1.5">
                        {f.tags.map((t) => (
                          <span key={t} className="rounded-full bg-brand-subtle px-2 py-0.5 text-xs text-dark-teal">#{t}</span>
                        ))}
                      </div>
                    )}
                  </div>
                ) : (
                  <p className="mt-2 line-clamp-1 text-sm text-muted-foreground">{f.answer}</p>
                )}
              </div>
            ))
          )}
        </div>
      </section>

      {/* All FAQ Entries — management table */}
      <section className="mt-10">
        <h2 className="text-h3 font-bold text-foreground">All FAQ Entries</h2>
        <p className="mt-1 mb-4 text-sm text-muted-foreground">
          HR can create, edit, categorize, publish, and archive FAQ entries. Pin key FAQs for
          onboarding or policy updates.
        </p>

        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading FAQs…</p>
        ) : isError ? (
          <p className="text-sm text-destructive">Could not load FAQs.</p>
        ) : (
          <DataTable columns={columns} rows={tableRows} rowKey={(f) => f.id} empty="No FAQs match your filters." />
        )}
      </section>

      <FaqFormDialog open={dialogOpen} onOpenChange={setDialogOpen} faq={editing} />
    </div>
  );
}

function ActionLink({
  onClick,
  className,
  children,
}: {
  onClick: () => void;
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <button type="button" onClick={onClick} className={cn("font-medium hover:underline", className)}>
      {children}
    </button>
  );
}
