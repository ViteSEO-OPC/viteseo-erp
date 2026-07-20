"use client";

import { useEffect, useRef, useState } from "react";
import { Plus, RefreshCw } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";
import { AxiosError } from "axios";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { PageHeader } from "@/core/components/page-header";
import { DataTable, type Column } from "@/core/components/data-table";
import { HolidayFormDialog } from "@/modules/hr/components/holiday-form-dialog";
import { DeleteConfirmDialog } from "@/modules/hr/components/delete-confirm-dialog";
import {
  useHolidaySets,
  useHolidays,
  useCreateHolidaySet,
  useRefreshHolidaySet,
  useDeleteHoliday,
} from "@/modules/hr/hooks/use-holidays";
import {
  COUNTRIES,
  countryName,
  type Holiday,
} from "@/modules/hr/types/holiday";

const YEARS = [2024, 2025, 2026, 2027, 2028];

const selectClass =
  "h-9 rounded-lg border border-border bg-card px-3 text-sm font-medium text-foreground outline-none focus-visible:border-ring";

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
        <div className="flex items-center gap-3">
          <select aria-label="Country" className={selectClass} value={country} onChange={(e) => setCountry(e.target.value)}>
            {COUNTRIES.map((c) => (
              <option key={c.code} value={c.code}>{flagEmoji(c.code)} {c.name}</option>
            ))}
          </select>
          <select aria-label="Year" className={selectClass} value={year} onChange={(e) => setYear(e.target.value)}>
            {YEARS.map((y) => (
              <option key={y} value={y}>{y}</option>
            ))}
          </select>
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
          holidays. Miss Chris can add, edit, or remove entries. Holiday sets are maintained per
          country per year.
        </p>

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
