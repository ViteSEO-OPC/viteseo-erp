"use client";

import { useMemo, useState } from "react";
import {
  Plus,
  Pencil,
  Trash2,
  Paperclip,
  Eye,
  EyeOff,
  Calendar,
  ChevronDown,
} from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/core/components/page-header";
import { DataTable, PrimaryCell, type Column } from "@/core/components/data-table";
import { formatDate } from "@/core/lib/format";
import { OfficeExpenseFormDialog } from "@/modules/hr/components/office-expense-form-dialog";
import { DeleteConfirmDialog } from "@/modules/hr/components/delete-confirm-dialog";
import {
  useOfficeExpenses,
  useDeleteOfficeExpense,
} from "@/modules/hr/hooks/use-office-expenses";
import {
  expenseCategoryLabel,
  type OfficeExpense,
} from "@/modules/hr/types/office-expense";

type Period = "all" | "week" | "month" | "year";
type Sort = "date_desc" | "amount_desc" | "amount_asc";

const PERIOD_LABELS: Record<Period, string> = {
  all: "All time",
  week: "This week",
  month: "This month",
  year: "This year",
};

/** Whole-peso, with a thin space after the sign — matches the Figma numerals. */
function peso(n: number): string {
  return `₱ ${n.toLocaleString("en-PH", { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`;
}

/** Today as a local calendar date, resolved in Asia/Manila (project TZ rule). */
function manilaToday(): Date {
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Manila",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).formatToParts(new Date());
  const get = (t: string) => Number(parts.find((p) => p.type === t)?.value);
  return new Date(get("year"), get("month") - 1, get("day"));
}

/** "YYYY-MM-DD" → local Date at midnight. */
function parseYmd(s: string): Date {
  const [y, m, d] = s.split("-").map(Number);
  return new Date(y, (m ?? 1) - 1, d ?? 1);
}

/** Inclusive [start, end] window for the chosen period, or null for "all". */
function periodRange(period: Period, today: Date): { start: Date; end: Date } | null {
  const y = today.getFullYear();
  const mo = today.getMonth();
  if (period === "all") return null;
  if (period === "year") return { start: new Date(y, 0, 1), end: new Date(y, 11, 31) };
  if (period === "month") return { start: new Date(y, mo, 1), end: new Date(y, mo + 1, 0) };
  // week — Monday through Sunday of the current week.
  const daysSinceMonday = (today.getDay() + 6) % 7;
  const start = new Date(today);
  start.setDate(today.getDate() - daysSinceMonday);
  const end = new Date(start);
  end.setDate(start.getDate() + 6);
  return { start, end };
}

export default function OfficeExpensesPage() {
  const { data: rows, isLoading, isError } = useOfficeExpenses();
  const deleteMut = useDeleteOfficeExpense();

  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<OfficeExpense | null>(null);
  const [deleting, setDeleting] = useState<OfficeExpense | null>(null);
  const [period, setPeriod] = useState<Period>("all");
  const [sort, setSort] = useState<Sort>("date_desc");
  // Which stat-card amounts are masked (privacy show/hide toggle).
  const [hidden, setHidden] = useState<Record<string, boolean>>({});
  const toggle = (key: string) => setHidden((h) => ({ ...h, [key]: !h[key] }));

  // 1) filter by date period
  const filtered = useMemo(() => {
    const range = periodRange(period, manilaToday());
    if (!range) return rows ?? [];
    return (rows ?? []).filter((e) => {
      const d = parseYmd(e.expense_date);
      return d >= range.start && d <= range.end;
    });
  }, [rows, period]);

  // 2) sort (by amount either direction, or newest date by default)
  const sorted = useMemo(() => {
    const arr = [...filtered];
    if (sort === "amount_desc") arr.sort((a, b) => b.amount - a.amount);
    else if (sort === "amount_asc") arr.sort((a, b) => a.amount - b.amount);
    else arr.sort((a, b) => b.expense_date.localeCompare(a.expense_date));
    return arr;
  }, [filtered, sort]);

  const total = useMemo(() => filtered.reduce((s, e) => s + e.amount, 0), [filtered]);

  // Top two categories by spend, for the summary cards.
  const topCategories = useMemo(() => {
    const m = new Map<string, number>();
    for (const e of filtered) m.set(e.category, (m.get(e.category) ?? 0) + e.amount);
    return [...m.entries()].sort((a, b) => b[1] - a[1]).slice(0, 2);
  }, [filtered]);

  async function performDelete(e: OfficeExpense) {
    try {
      await deleteMut.mutateAsync(e.id);
      toast.success("Expense deleted");
    } catch {
      toast.error("Could not delete.");
    }
  }

  const columns: Column<OfficeExpense>[] = [
    { header: "Date", cell: (e) => <span className="text-muted-foreground">{formatDate(e.expense_date)}</span>, className: "whitespace-nowrap" },
    { header: "Description", cell: (e) => <PrimaryCell title={e.description} /> },
    { header: "Category", cell: (e) => <span className="text-muted-foreground">{expenseCategoryLabel(e.category)}</span> },
    { header: "Amount", cell: (e) => <span className="font-semibold text-foreground">{peso(e.amount)}</span> },
    {
      header: "",
      align: "right",
      cell: (e) => (
        <div className="flex justify-end gap-1">
          {e.receipt_url && (
            <Button size="icon-sm" variant="ghost" asChild title="View receipt">
              <a href={e.receipt_url} target="_blank" rel="noreferrer">
                <Paperclip />
                <span className="sr-only">View receipt</span>
              </a>
            </Button>
          )}
          <Button size="icon-sm" variant="ghost" onClick={() => { setEditing(e); setDialogOpen(true); }}>
            <Pencil />
            <span className="sr-only">Edit</span>
          </Button>
          <Button size="icon-sm" variant="ghost" onClick={() => setDeleting(e)}>
            <Trash2 />
            <span className="sr-only">Delete</span>
          </Button>
        </div>
      ),
    },
  ];

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Office Expenses"
        subtitle="Track company and office spending."
        actions={
          <div className="flex items-center gap-3">
            <Button onClick={() => { setEditing(null); setDialogOpen(true); }}>
              <Plus />
              Add Expense
            </Button>
            <div className="relative">
              <Calendar className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <select
                aria-label="Filter by period"
                value={period}
                onChange={(e) => setPeriod(e.target.value as Period)}
                className="h-9 appearance-none rounded-lg border border-border bg-card pl-9 pr-8 text-sm font-medium text-foreground outline-none focus-visible:border-ring"
              >
                <option value="all">All time</option>
                <option value="week">This week</option>
                <option value="month">This month</option>
                <option value="year">This year</option>
              </select>
              <ChevronDown className="pointer-events-none absolute right-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            </div>
          </div>
        }
      />

      {/* Summary cards — money cards have a show/hide (eye) toggle */}
      <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <MoneyStatCard
          label="Total Expenses"
          value={peso(total)}
          hidden={!!hidden.total}
          onToggle={() => toggle("total")}
        />
        <MoneyStatCard
          label={topCategories[0] ? `${expenseCategoryLabel(topCategories[0][0])} Expenses` : "Top Category"}
          value={topCategories[0] ? peso(topCategories[0][1]) : "₱ 0"}
          hidden={!!hidden.cat1}
          onToggle={() => toggle("cat1")}
        />
        <MoneyStatCard
          label={topCategories[1] ? `${expenseCategoryLabel(topCategories[1][0])} Expenses` : "Next Category"}
          value={topCategories[1] ? peso(topCategories[1][1]) : "₱ 0"}
          hidden={!!hidden.cat2}
          onToggle={() => toggle("cat2")}
        />
        <div className="rounded-card border border-border bg-card p-5">
          <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">Entries</p>
          <p className="mt-2 text-h2 font-bold text-foreground">{filtered.length}</p>
          <p className="mt-1 text-caption text-muted-foreground">{PERIOD_LABELS[period]}</p>
        </div>
      </div>

      {/* Sort control */}
      <div className="mt-6 flex justify-end">
        <label className="flex items-center gap-2 text-sm text-muted-foreground">
          Sort
          <select
            className="h-9 rounded-lg border border-input bg-transparent px-2.5 text-sm text-foreground outline-none focus-visible:border-ring"
            value={sort}
            onChange={(e) => setSort(e.target.value as Sort)}
          >
            <option value="date_desc">Date (newest)</option>
            <option value="amount_desc">Amount (high → low)</option>
            <option value="amount_asc">Amount (low → high)</option>
          </select>
        </label>
      </div>

      <div className="mt-4">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading expenses…</p>
        ) : isError ? (
          <p className="text-sm text-destructive">Could not load expenses.</p>
        ) : (
          <DataTable
            title="Expense Breakdown"
            titleRight={<span className="text-h3 font-bold text-foreground">{PERIOD_LABELS[period]}</span>}
            columns={columns}
            rows={sorted}
            rowKey={(e) => e.id}
            pageSize={10}
            empty="No office expenses in this period."
          />
        )}
      </div>

      <OfficeExpenseFormDialog open={dialogOpen} onOpenChange={setDialogOpen} expense={editing} />

      <DeleteConfirmDialog
        open={!!deleting}
        onOpenChange={(o) => !o && setDeleting(null)}
        title="Delete expense"
        entityName={deleting?.description}
        description="This action cannot be undone. The expense will be permanently removed from the records."
        onConfirm={() => performDelete(deleting!)}
      />
    </div>
  );
}

/** Summary card with a privacy show/hide (eye) toggle beside the amount. */
function MoneyStatCard({
  label,
  value,
  hidden,
  onToggle,
}: {
  label: string;
  value: string;
  hidden: boolean;
  onToggle: () => void;
}) {
  return (
    <div className="flex items-center justify-between gap-3 rounded-card border border-border bg-card p-5">
      <div className="min-w-0">
        <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">{label}</p>
        <p className="mt-2 text-h2 font-bold text-foreground">{hidden ? "₱ ••••••" : value}</p>
      </div>
      <button
        type="button"
        onClick={onToggle}
        aria-label={hidden ? "Show amount" : "Hide amount"}
        className="shrink-0 rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
      >
        {hidden ? <EyeOff className="size-5" /> : <Eye className="size-5" />}
      </button>
    </div>
  );
}
