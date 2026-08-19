"use client";

import { useEffect, useRef, useState } from "react";
import { CloudOff, Plus, RefreshCw } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";
import { AxiosError } from "axios";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { PageHeader } from "@/modules/hr/components/page-header";
import { DataTable, type Column } from "@/core/components/data-table";
import { HolidayFormDialog } from "@/modules/hr/components/holiday-form-dialog";
import { WorkSuspensionFormDialog } from "@/modules/hr/components/work-suspension-form-dialog";
import { DeleteConfirmDialog } from "@/modules/hr/components/delete-confirm-dialog";
import {
  useHolidaySets,
  useHolidays,
  useCreateHolidaySet,
  useRefreshHolidaySet,
  useDeleteHoliday,
} from "@/modules/hr/hooks/use-holidays";
import {
  useWorkSuspensions,
  useDeleteWorkSuspension,
} from "@/modules/hr/hooks/use-work-suspensions";
import {
  COUNTRIES,
  countryName,
  type Holiday,
  type WorkSuspension,
} from "@/modules/hr/types/holiday";
import { SimpleSelect } from "@/modules/hr/ui/select";

const YEARS = [2024, 2025, 2026, 2027, 2028];

/**
 * The company's home country. Only this calendar drives pay, so only its unclassified
 * holidays block a payroll run (mirrors HolidayService::HOME_COUNTRY).
 */
const HOME_COUNTRY = "PH";

// Figma type colors: Regular = green, Special = blue, Special Working = amber.
const TYPE_STYLE: Record<string, string> = {
  regular: "bg-success-subtle text-success",
  special_non_working: "bg-info-subtle text-info",
  special_working: "bg-warning-subtle text-warning",
};
const TYPE_SHORT_LABEL: Record<string, string> = {
  regular: "Regular",
  special_non_working: "Special",
  special_working: "Special Working",
};

/** ISO-2 country code → flag emoji (regional indicators). */
function flagEmoji(code: string): string {
  return code
    .toUpperCase()
    .replace(/./g, (c) => String.fromCodePoint(127397 + c.charCodeAt(0)));
}

function parseYmd(dateStr: string): Date {
  const [y, m, d] = dateStr.split("-").map(Number);
  return new Date(y, (m ?? 1) - 1, d ?? 1);
}
function shortDate(dateStr: string): string {
  return parseYmd(dateStr).toLocaleDateString("en-US", { month: "short", day: "numeric" });
}
function weekday(dateStr: string): string {
  return parseYmd(dateStr).toLocaleDateString("en-US", { weekday: "long" });
}

export default function HolidaysPage() {
  const { data: sets } = useHolidaySets();
  const createMut = useCreateHolidaySet();
  const refreshMut = useRefreshHolidaySet();

  const [country, setCountry] = useState("PH");
  const [year, setYear] = useState("2026");

  const activeSet =
    sets?.find((s) => s.country_code === country && s.year === Number(year)) ?? null;
  const { data: holidays } = useHolidays(activeSet?.id ?? null);
  // Holidays HR hasn't typed yet — these are what block a payroll run (Slice 2).
  const unclassified = (holidays ?? []).filter((h) => !h.type);
  const deleteHolidayMut = useDeleteHoliday(activeSet?.id ?? 0);

  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<Holiday | null>(null);
  const [deleting, setDeleting] = useState<Holiday | null>(null);

  // Selecting a country/year auto-loads its national holidays (once per key).
  const attempted = useRef<Set<string>>(new Set());
  async function load() {
    const key = `${country}-${year}`;
    attempted.current.add(key);
    try {
      const set = await createMut.mutateAsync({ country_code: country, year: Number(year) });
      toast.success(`Loaded ${countryName(country)} ${year}`, `${set.holidays_count} holidays imported.`);
    } catch (err) {
      attempted.current.delete(key); // allow a manual retry
      const msg =
        err instanceof AxiosError
          ? (err.response?.data?.errors?.country_code?.[0] ?? err.response?.data?.message ?? "Could not load holidays.")
          : "Could not load holidays.";
      toast.error(msg);
    }
  }

  useEffect(() => {
    if (!sets) return; // sets still loading
    const key = `${country}-${year}`;
    if (activeSet || attempted.current.has(key) || createMut.isPending) return;
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sets, activeSet, country, year]);

  async function onRefresh() {
    if (!activeSet) return;
    try {
      await refreshMut.mutateAsync(activeSet.id);
      toast.success(`Refreshed for ${year}`);
    } catch {
      toast.error("Could not refresh from the provider.");
    }
  }

  const columns: Column<Holiday>[] = [
    { header: "Date", cell: (h) => <span className="text-muted-foreground">{shortDate(h.date)}</span>, className: "whitespace-nowrap" },
    { header: "Day", cell: (h) => <span className="text-muted-foreground">{weekday(h.date)}</span> },
    { header: "Holiday Name", cell: (h) => <span className="font-medium text-foreground">{h.name}</span> },
    {
      header: "Type",
      cell: (h) =>
        h.type ? (
          <span className={cn("inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold", TYPE_STYLE[h.type] ?? "bg-muted text-muted-foreground")}>
            {TYPE_SHORT_LABEL[h.type] ?? h.type}
          </span>
        ) : (
          <button
            type="button"
            onClick={() => { setEditing(h); setDialogOpen(true); }}
            className="inline-flex items-center rounded-full border border-dashed border-warning px-2.5 py-0.5 text-xs font-semibold text-warning hover:bg-warning-subtle"
          >
            Set type
          </button>
        ),
    },
    {
      header: "Actions",
      align: "right",
      cell: (h) => (
        <div className="flex justify-end gap-3 text-sm font-medium">
          <button type="button" className="text-brand hover:underline" onClick={() => { setEditing(h); setDialogOpen(true); }}>
            Edit
          </button>
          <button
            type="button"
            className="text-danger hover:underline"
            onClick={() => setDeleting(h)}
          >
            Remove
          </button>
        </div>
      ),
    },
  ];

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Holiday List"
        subtitle="Manage company holidays by country and year. Holidays are customizable, country-based, and refreshed annually."
      />

      {/* Toolbar: country + year on the left, refresh + add on the right */}
      <div className="mt-6 flex flex-wrap items-center justify-between gap-3">
        {/*
          At 375px the page has 343px of content, and `w-56` + gap-3 + `w-28` is
          348px — with neither select able to shrink, the year dropdown ran off
          the right edge and took the page's horizontal scrollbar with it.

          On phones the country select takes whatever is left over instead of a
          fixed 224px, and the year keeps its width (it holds four digits, so it
          has nothing to spare). From `sm` up both return to the fixed widths.
        */}
        <div className="flex w-full items-center gap-3 sm:w-auto">
          <SimpleSelect
            aria-label="Country"
            className="min-w-0 flex-1 sm:w-56 sm:flex-none"
            value={country}
            onValueChange={setCountry}
            options={COUNTRIES.map((c) => ({ value: c.code, label: `${flagEmoji(c.code)} ${c.name}` }))}
          />
          <SimpleSelect
            aria-label="Year"
            className="w-28 shrink-0"
            value={String(year)}
            onValueChange={setYear}
            options={YEARS.map((y) => ({ value: String(y), label: String(y) }))}
          />
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={onRefresh} disabled={!activeSet || refreshMut.isPending}>
            <RefreshCw />
            {refreshMut.isPending ? "Refreshing…" : `Refresh for ${year}`}
          </Button>
          <Button onClick={() => { setEditing(null); setDialogOpen(true); }} disabled={!activeSet}>
            <Plus />
            Add Holiday
          </Button>
        </div>
      </div>

      {/* Set heading + description */}
      <div className="mt-8">
        <h2 className="text-h3 font-bold text-foreground">
          {countryName(country)} - {year} Holidays
        </h2>
        <p className="mt-1 mb-4 text-sm text-muted-foreground">
          Showing regular holidays (excludes weekends). Selecting a country auto-loads its national
          holidays.
        </p>

        {/* Payroll prices a day off the holiday TYPE, so an un-typed date blocks the run. */}
        {country === HOME_COUNTRY && unclassified.length > 0 && (
          <div className="mb-4 rounded-card border border-warning bg-warning-subtle p-4">
            <p className="text-sm font-semibold text-warning">
              {unclassified.length} holiday{unclassified.length === 1 ? "" : "s"} still need
              {unclassified.length === 1 ? "s" : ""} a type
            </p>
            <p className="mt-1 text-sm text-foreground">
              Payroll can&apos;t run for a period containing an unclassified holiday — the type
              decides the premium (Regular 200%, Special 130%). Use{" "}
              <span className="font-medium">Set type</span> on the rows below.
            </p>
            <p className="mt-2 text-sm text-muted-foreground">
              {unclassified.map((h) => `${shortDate(h.date)} — ${h.name}`).join(" · ")}
            </p>
          </div>
        )}

        {activeSet ? (
          <DataTable columns={columns} rows={holidays ?? []} rowKey={(h) => h.id} pageSize={0} empty="No holidays in this set." />
        ) : createMut.isPending ? (
          <p className="text-sm text-muted-foreground">Loading {countryName(country)} {year} holidays…</p>
        ) : (
          <div className="rounded-card border border-dashed border-border bg-card p-10 text-center">
            <p className="text-sm font-medium text-foreground">
              No holidays loaded for {countryName(country)} {year}.
            </p>
            <Button className="mt-3" variant="outline" size="sm" onClick={load}>
              Load holidays
            </Button>
          </div>
        )}
      </div>

      <WorkSuspensionsSection year={year} />

      {activeSet && (
        <HolidayFormDialog open={dialogOpen} onOpenChange={setDialogOpen} setId={activeSet.id} holiday={editing} />
      )}

      <DeleteConfirmDialog
        open={!!deleting}
        onOpenChange={(o) => !o && setDeleting(null)}
        title="Remove holiday"
        question={`Are you sure you want to remove “${deleting?.name ?? ""}”?`}
        description="This action cannot be undone. The holiday will be removed from this set."
        confirmLabel="Remove"
        onConfirm={async () => {
          try {
            await deleteHolidayMut.mutateAsync(deleting!.id);
            toast.success("Removed");
          } catch {
            toast.error("Could not remove.");
          }
        }}
      />
    </div>
  );
}

/**
 * HR-declared non-working days — typhoons, shutdowns.
 *
 * On this page but NOT in the holiday table, which is the point the copy has to carry: a
 * holiday is a legal calendar date whose class decides a statutory premium, while this is the
 * company cancelling one day and saying whether it is still paid. They live side by side
 * because HR looks for both in the same place; they are separate because payroll prices them
 * differently and always will.
 */
function WorkSuspensionsSection({ year }: { year: string }) {
  const { data: suspensions, isLoading } = useWorkSuspensions();
  const deleteMut = useDeleteWorkSuspension();

  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<WorkSuspension | null>(null);
  const [deleting, setDeleting] = useState<WorkSuspension | null>(null);

  // Scoped to the year the page is already showing, so the two tables agree about what
  // period is on screen.
  const rows = (suspensions ?? []).filter((s) => s.date.startsWith(year));

  const columns: Column<WorkSuspension>[] = [
    {
      header: "Date",
      cell: (s) => <span className="text-muted-foreground">{shortDate(s.date)}</span>,
      className: "whitespace-nowrap",
    },
    { header: "Day", cell: (s) => <span className="text-muted-foreground">{weekday(s.date)}</span> },
    { header: "Reason", cell: (s) => <span className="font-medium text-foreground">{s.name}</span> },
    {
      header: "Pay",
      cell: (s) => (
        <span
          className={cn(
            "inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold",
            s.is_paid ? "bg-success-subtle text-success" : "bg-danger-subtle text-danger",
          )}
        >
          {s.is_paid ? "Paid" : "Unpaid · 1 day"}
        </span>
      ),
    },
    {
      header: "Declared by",
      cell: (s) => <span className="text-muted-foreground">{s.declared_by ?? "—"}</span>,
    },
    {
      header: "Actions",
      align: "right",
      cell: (s) => (
        <div className="flex justify-end gap-3 text-sm font-medium">
          <button
            type="button"
            className="text-brand hover:underline"
            onClick={() => {
              setEditing(s);
              setDialogOpen(true);
            }}
          >
            Edit
          </button>
          <button type="button" className="text-danger hover:underline" onClick={() => setDeleting(s)}>
            Withdraw
          </button>
        </div>
      ),
    },
  ];

  return (
    <div className="mt-12">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-h3 font-bold text-foreground">Work Suspensions</h2>
          <p className="mt-1 mb-4 max-w-2xl text-sm text-muted-foreground">
            Days the company cancelled — a typhoon, a flood, a shutdown. Nobody has to time in
            and nobody is flagged absent. Paid means the day costs no one a day&apos;s pay;
            unpaid deducts one day from everyone who didn&apos;t work it. These are separate from
            holidays, which are set by law and carry their own premium.
          </p>
        </div>
        <Button
          onClick={() => {
            setEditing(null);
            setDialogOpen(true);
          }}
        >
          <Plus />
          Declare Non-Working Day
        </Button>
      </div>

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading work suspensions…</p>
      ) : rows.length > 0 ? (
        <DataTable columns={columns} rows={rows} rowKey={(s) => s.id} pageSize={0} empty="" />
      ) : (
        <div className="rounded-card border border-dashed border-border bg-card p-10 text-center">
          <CloudOff className="mx-auto size-8 text-muted-foreground" />
          <p className="mt-2 text-sm font-medium text-foreground">
            No non-working days declared for {year}.
          </p>
          <p className="mt-1 text-sm text-muted-foreground">
            Declare one when work is cancelled, so the day isn&apos;t counted as everyone being
            absent.
          </p>
        </div>
      )}

      <WorkSuspensionFormDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        suspension={editing}
      />

      <DeleteConfirmDialog
        open={!!deleting}
        onOpenChange={(o) => !o && setDeleting(null)}
        title="Withdraw non-working day"
        question={`Withdraw “${deleting?.name ?? ""}” on ${deleting ? shortDate(deleting.date) : ""}?`}
        description="The day goes back to being an ordinary workday — absences and violations apply to it again. Re-run any payroll that covered it."
        confirmLabel="Withdraw"
        onConfirm={async () => {
          try {
            await deleteMut.mutateAsync(deleting!.id);
            toast.success("Withdrawn", "That date is an ordinary workday again.");
          } catch (err) {
            const msg =
              err instanceof AxiosError
                ? (err.response?.data?.errors?.date?.[0] ?? "Could not withdraw it.")
                : "Could not withdraw it.";
            toast.error(msg);
          }
        }}
      />
    </div>
  );
}
