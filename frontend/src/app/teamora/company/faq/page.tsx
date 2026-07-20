"use client";

import { useState } from "react";
import { Plus, Pin, Search, Archive, Pencil, Trash2, Send, RotateCcw } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/core/components/page-header";
import { DataTable, type Column } from "@/core/components/data-table";
import { StatusBadge } from "@/core/components/status-badge";
import { formatDate } from "@/core/lib/format";
import { FaqFormDialog } from "@/modules/hr/components/faq-form-dialog";
import { DeleteConfirmDialog } from "@/modules/hr/components/delete-confirm-dialog";
import {
  useFaqs,
  useDeleteFaq,
  useSetFaqStatus,
  usePinFaq,
} from "@/modules/hr/hooks/use-faqs";
import { FAQ_CATEGORIES, faqCategoryLabel, type Faq } from "@/modules/hr/types/faq";

const selectClass =
  "h-9 rounded-lg border border-input bg-transparent px-2.5 text-sm text-foreground outline-none focus-visible:border-ring";

// Category → token colors for the pill badge (matches the Figma legend).
const CATEGORY_STYLE: Record<string, string> = {
  leave: "bg-success-subtle text-success",
  payroll: "bg-info-subtle text-info",
  attendance: "bg-warning-subtle text-warning",
  benefits: "bg-violet-subtle text-violet",
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
  const [deleting, setDeleting] = useState<Faq | null>(null);

  async function run(fn: () => Promise<unknown>, ok: string) {
    try {
      await fn();
      toast.success(ok);
    } catch {
      toast.error("Something went wrong.");
    }
  }

  const byCategory = (faqs ?? []).filter((f) => category === "all" || f.category === category);
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
      cell: (f) => (
        <div className="flex items-center gap-1">
          {/* Status transition — contextual leading action */}
          {f.status === "published" ? (
            <IconAction
              icon={Archive}
              label="Archive"
              className="text-muted-foreground"
              onClick={() => run(() => statusMut.mutateAsync({ id: f.id, action: "archive" }), "Archived")}
            />
          ) : f.status === "archived" ? (
            <IconAction
              icon={RotateCcw}
              label="Restore"
              className="text-info"
              onClick={() => run(() => statusMut.mutateAsync({ id: f.id, action: "publish" }), "Restored")}
            />
          ) : (
            <IconAction
              icon={Send}
              label="Publish"
              className="text-success"
              onClick={() => run(() => statusMut.mutateAsync({ id: f.id, action: "publish" }), "Published")}
            />
          )}

          {/* Pin — red + filled when pinned */}
          <IconAction
            icon={Pin}
            label={f.is_pinned ? "Unpin" : "Pin"}
            className={cn(f.is_pinned ? "text-danger [&>svg]:fill-current" : "text-muted-foreground")}
            onClick={() => run(() => pinMut.mutateAsync(f.id), f.is_pinned ? "Unpinned" : "Pinned")}
          />

          <IconAction
            icon={Pencil}
            label="Edit"
            className="text-muted-foreground"
            onClick={() => { setEditing(f); setDialogOpen(true); }}
          />
          <IconAction
            icon={Trash2}
            label="Delete"
            className="text-danger"
            onClick={() => setDeleting(f)}
          />
        </div>
      ),
    },
  ];

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="FAQ"
        subtitle="Author the self-service knowledge base. Employees read the published entries."
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

      {/* FAQ management table */}
      <section className="mt-8">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading FAQs…</p>
        ) : isError ? (
          <p className="text-sm text-destructive">Could not load FAQs.</p>
        ) : (
          <DataTable columns={columns} rows={tableRows} rowKey={(f) => f.id} empty="No FAQs match your filters." />
        )}
      </section>

      <FaqFormDialog open={dialogOpen} onOpenChange={setDialogOpen} faq={editing} />

      <DeleteConfirmDialog
        open={!!deleting}
        onOpenChange={(o) => !o && setDeleting(null)}
        title="Delete FAQ"
        entityName={deleting?.question}
        description="This action cannot be undone. This FAQ will be permanently removed and will no longer be visible to employees."
        onConfirm={() => run(() => deleteMut.mutateAsync(deleting!.id), "Deleted")}
      />
    </div>
  );
}

function IconAction({
  icon: Icon,
  label,
  onClick,
  className,
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  onClick: () => void;
  className?: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      title={label}
      aria-label={label}
      className={cn(
        "flex size-8 items-center justify-center rounded-lg transition-colors hover:bg-muted",
        className,
      )}
    >
      <Icon className="size-[18px]" />
    </button>
  );
}
