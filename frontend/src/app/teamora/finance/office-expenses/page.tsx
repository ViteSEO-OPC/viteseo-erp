"use client";

import { useMemo, useState } from "react";
import {
  Plus,
  Pencil,
  Trash2,
  Paperclip,
  Calendar,
} from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/modules/hr/components/page-header";
import type { StatusFilterOption } from "@/core/components/status-filter";
import { useServerTable } from "@/modules/hr/hooks/use-server-table";
import type { DateWindow } from "@/modules/hr/api/queue";
import { DataTable, PrimaryCell, type Column } from "@/core/components/data-table";
import { formatDate } from "@/core/lib/format";
import { OfficeExpenseFormDialog } from "@/modules/hr/components/office-expense-form-dialog";
import { MoneyStatCard } from "@/modules/hr/components/money-stat-card";
import { StatusBadge } from "@/core/components/status-badge";
import { DeleteConfirmDialog } from "@/modules/hr/components/delete-confirm-dialog";
import {
  useOfficeExpenses,
  useOfficeExpenseSummary,
  useDeleteOfficeExpense,
} from "@/modules/hr/hooks/use-office-expenses";
import {
  expenseCategoryLabel,
  type OfficeExpense,
} from "@/modules/hr/types/office-expense";
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectSeparator,
  SelectTrigger,
  SelectValue,
  SimpleSelect,
} from "@/modules/hr/ui/select";

type Period = "all" | "week" | "month" | "year";
type Sort = "date_desc" | "amount_desc" | "amount_asc";

const PERIOD_LABELS: Record<Period, string> = {
  all: "All time",
  week: "This week",
  month: "This month",
  year: "This year",
};

/**
 * How a specific month is spelled in the picker's value: "m:2025-12".
 *
 * ONE control, not two. A named period and a month are the same question — which slice of the
 * ledger am I looking at — and splitting them across two dropdowns lets the screen hold an answer
 * to each, leaving the reader to work out which one the table obeyed.
 */
const MONTH_PREFIX = "m:";

/** The picked month as "YYYY-MM", or "" when the value names a period instead. */
function monthOf(range: string): string {
  return range.startsWith(MONTH_PREFIX) ? range.slice(MONTH_PREFIX.length) : "";
}

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
/** A Date back to the 'Y-m-d' the API filters on. */
function ymd(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
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

/**
 * The window the ledger is read through, as the API wants it. `{}` is all time.
 *
 * A picked month is resolved from its own "YYYY-MM" rather than from today, so it stays the
 * month that was chosen — day 0 of the next month is the last day of this one, which is also
 * what makes February right without a leap-year rule.
 */
function periodWindow(range: string, today: Date): DateWindow {
  const month = monthOf(range);

  if (month) {
    const [y, m] = month.split("-").map(Number);
    return { from: `${month}-01`, to: ymd(new Date(y, m, 0)) };
  }

  const bounds = periodRange(range as Period, today);

  return bounds ? { from: ymd(bounds.start), to: ymd(bounds.end) } : {};
}

/** Curated: server mode cannot read the vocabulary off one page of rows. */
const EXPENSE_STATUS_OPTIONS: StatusFilterOption[] = [
  { value: "all", label: "All" },
  { value: "recorded", label: "Recorded" },
  { value: "approved", label: "Approved" },
  { value: "rejected", label: "Rejected" },
];

export default function OfficeExpensesPage() {
  // A named period ("all", "week", …) or one month ("m:2025-12") — the picker's single value.
  const [range, setRange] = useState<string>("all");
  const [sort, setSort] = useState<Sort>("date_desc");

  const dateWindow = useMemo(() => periodWindow(range, manilaToday()), [range]);

  // Server-paged, server-filtered, server-SORTED. The period picker and the sort control both
  // reach the query now: applied in the browser they could only ever reorder or narrow the ten
  // rows already on screen, which for a ledger that grows with every purchase is a different
  // answer than the one the controls appear to give.
  //
  // The window goes through the table hook rather than around it, which is what returns the
  // ledger to page 1 when a narrower period is chosen — page 4 of "all time" is past the end of
  // most single months, and the empty table that followed looked like a month with no spend.
  const table = useServerTable(10, dateWindow);
  const query = { ...table.params, sort };
  const { data, isLoading, isError, isFetching } = useOfficeExpenses(query);
  // Counted by the database over the same window the table below is showing.
  const { data: spend } = useOfficeExpenseSummary(query);
  const sorted = data?.items ?? [];
  const deleteMut = useDeleteOfficeExpense();

  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<OfficeExpense | null>(null);
  const [deleting, setDeleting] = useState<OfficeExpense | null>(null);
  // Which stat-card amounts are masked (privacy show/hide toggle).
  const [hidden, setHidden] = useState<Record<string, boolean>>({});
  const toggle = (key: string) => setHidden((h) => ({ ...h, [key]: !h[key] }));

  const total = spend?.total ?? 0;

  // Only the months the ledger actually has spend in, newest first — offering an empty month
  // would be offering a guaranteed blank screen. They come with the cards, so they describe the
  // whole ledger rather than the window they set.
  const months = useMemo(() => spend?.months ?? [], [spend]);

  // Every card that names the period says the MONTH once one is picked: "This month" on a screen
  // showing June is a caption disagreeing with the table under it.
  const month = monthOf(range);
  const periodLabel = month
    ? (months.find((m) => m.value === month)?.label ?? month)
    : PERIOD_LABELS[range as Period];

  // Top two categories by spend, from the server's own tally — the browser could only have
  // added up the page.
  const topCategories = useMemo<[string, number][]>(
    () => Object.entries(spend?.by_category ?? {}).slice(0, 2) as [string, number][],
    [spend],
  );

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
      header: "Status",
      cell: (e) => (
        <div className="flex flex-col gap-0.5">
          <StatusBadge status={e.status} className="w-fit" />
          {/* Who decided, so an approved row is attributable without opening it. */}
          {e.reviewed_by && (
            <span className="text-caption text-muted-foreground">
              by {e.reviewed_by}
            </span>
          )}
        </div>
      ),
      className: "whitespace-nowrap",
    },
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
            <Select value={range} onValueChange={setRange}>
              <SelectTrigger aria-label="Filter by period" className="relative w-44 pl-9">
                <Calendar className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All time</SelectItem>
                <SelectItem value="week">This week</SelectItem>
                <SelectItem value="month">This month</SelectItem>
                <SelectItem value="year">This year</SelectItem>

                {/*
                 * The months sit UNDER the named periods in the same list — one control answering
                 * one question. Only months the ledger has spend in are offered; an empty month
                 * is a guaranteed blank screen, so it is not worth a row.
                 */}
                {months.length > 0 && (
                  <SelectGroup>
                    <SelectSeparator />
                    <SelectLabel>By month</SelectLabel>
                    {months.map((m) => (
                      <SelectItem key={m.value} value={`${MONTH_PREFIX}${m.value}`}>
                        {m.label}
                      </SelectItem>
                    ))}
                  </SelectGroup>
                )}
              </SelectContent>
            </Select>
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
          <p className="mt-2 text-h2 font-bold text-foreground">{data?.total ?? 0}</p>
          <p className="mt-1 text-caption text-muted-foreground">{periodLabel}</p>
        </div>
      </div>

      {/* Sort control */}
      <div className="mt-6 flex justify-end">
        <label className="flex items-center gap-2 text-sm text-muted-foreground">
          Sort
          <SimpleSelect
            className="w-48"
            value={sort}
            onValueChange={(v) => setSort(v as Sort)}
            options={[
              { value: "date_desc", label: "Date (newest)" },
              { value: "amount_desc", label: "Amount (high → low)" },
              { value: "amount_asc", label: "Amount (low → high)" },
            ]}
          />
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
            titleRight={<span className="text-h3 font-bold text-foreground">{periodLabel}</span>}
            columns={columns}
            rows={sorted}
            rowKey={(e) => e.id}
            pageSize={table.pageSize}
            searchPlaceholder="Search description, category, vendor…"
            empty="No office expenses in this period."
            server={table.serverProps(data?.total ?? 0, isFetching, EXPENSE_STATUS_OPTIONS)}
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
