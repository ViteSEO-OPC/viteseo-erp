"use client";

import { useMemo, useState } from "react";
import { Plus, Search, ChevronLeft, ChevronRight } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import { PageHeader } from "@/modules/hr/components/page-header";
import { EmployeeCard } from "@/modules/hr/components/employee-card";
import { EmployeeFormDialog } from "@/modules/hr/components/employee-form-dialog";
import { useEmployees } from "@/modules/hr/hooks/use-employees";
import { useDepartments } from "@/modules/hr/hooks/use-departments";
import {
  EMPLOYMENT_STATUSES,
  statusLabel,
} from "@/modules/hr/types/employee";
import { SimpleSelect } from "@/modules/hr/ui/select";

// Fixed page size (12 fills the 1/2/3-column card grid evenly). Deliberately not
// user-selectable — see the note in DataTable.
const PAGE_SIZE = 12;

export default function EmployeesPage() {
  // MF-18 — which roster to fetch. This is a SERVER-side switch, not one of the client filters
  // below: a former employee is soft-deleted, so the default query cannot return them at all and
  // there was no way to find somebody to rehire. Separate from the `status` filter, which is
  // employment_status (regular / probationary / …) and a different question entirely.
  const [roster, setRoster] = useState<"active" | "former" | "all">("active");
  const { data: employees, isLoading, isError } = useEmployees(roster);
  const { data: departments } = useDepartments();

  const [search, setSearch] = useState("");
  const [dept, setDept] = useState("all");
  const [status, setStatus] = useState("all");
  const [dialogOpen, setDialogOpen] = useState(false);
  const [page, setPage] = useState(1);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return (employees ?? []).filter((e) => {
      const matchesSearch =
        !q ||
        e.name.toLowerCase().includes(q) ||
        e.job_title.toLowerCase().includes(q) ||
        e.email.toLowerCase().includes(q);
      const matchesDept = dept === "all" || e.department?.id === Number(dept);
      const matchesStatus = status === "all" || e.employment_status === status;
      return matchesSearch && matchesDept && matchesStatus;
    });
  }, [employees, search, dept, status]);

  const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
  const paged = filtered.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);
  const pagesToShow: (number | "ellipsis")[] = (() => {
    const result: (number | "ellipsis")[] = [];
    for (let i = 1; i <= totalPages; i++) {
      if (i === 1 || i === totalPages || (i >= page - 1 && i <= page + 1)) {
        result.push(i);
      } else if (result[result.length - 1] !== "ellipsis") {
        result.push("ellipsis");
      }
    }
    return result;
  })();

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Employees"
        subtitle={
          employees
            ? `Showing ${paged.length} of ${filtered.length} employees`
            : undefined
        }
        actions={
          <Button onClick={() => setDialogOpen(true)}>
            <Plus />
            Add Employee
          </Button>
        }
      />

      {/* Filters */}
      <div className="mt-6 flex flex-wrap items-center gap-2">
        <div className="relative w-full max-w-xs">
          <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            className="pl-8"
            placeholder="Search by name"
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
          />
        </div>
        <SimpleSelect
          className="w-56"
          value={dept}
          onValueChange={(v) => { setDept(v); setPage(1); }}
          options={[
            { value: "all", label: "All Departments" },
            ...(departments ?? []).map((d) => ({ value: String(d.id), label: d.name })),
          ]}
        />
        <SimpleSelect
          className="w-40"
          value={status}
          onValueChange={(v) => { setStatus(v); setPage(1); }}
          options={[
            { value: "all", label: "All Statuses" },
            ...EMPLOYMENT_STATUSES.map((s) => ({ value: s, label: statusLabel(s) })),
          ]}
        />
        {/* MF-18 — former staff are archived, so finding one to rehire needs its own switch. */}
        <SimpleSelect
          className="w-40"
          value={roster}
          onValueChange={(v) => { setRoster(v as "active" | "former" | "all"); setPage(1); }}
          options={[
            { value: "active", label: "Current staff" },
            { value: "former", label: "Former staff" },
            { value: "all", label: "Current + former" },
          ]}
        />
      </div>

      {/* Grid */}
      <div className="mt-6">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading employees…</p>
        ) : isError ? (
          <p className="text-sm text-destructive">Could not load employees.</p>
        ) : paged.length === 0 ? (
          <p className="text-sm text-muted-foreground">No employees match your filters.</p>
        ) : (
          <>
            <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
              {paged.map((e) => (
                <EmployeeCard key={e.id} employee={e} />
              ))}
            </div>

            {/* Pagination */}
            {totalPages > 1 && (
              <div className="mt-6 flex flex-wrap items-center justify-between gap-3">
                <div className="text-caption text-muted-foreground">
                  Showing {(page - 1) * PAGE_SIZE + 1}–{Math.min(page * PAGE_SIZE, filtered.length)} of{" "}
                  {filtered.length}
                </div>
                <div className="flex items-center gap-1">
                  <button
                    type="button"
                    disabled={page <= 1}
                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                    className="inline-flex size-7 items-center justify-center rounded-md border border-input bg-card text-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
                    aria-label="Previous page"
                  >
                    <ChevronLeft className="size-4" />
                  </button>
                  {pagesToShow.map((p, i) =>
                    p === "ellipsis" ? (
                      <span key={`e${i}`} className="px-1 text-sm text-muted-foreground">…</span>
                    ) : (
                      <button
                        key={p}
                        type="button"
                        onClick={() => setPage(p)}
                        className={cn(
                          "inline-flex size-7 items-center justify-center rounded-md text-sm transition-colors",
                          p === page
                            ? "bg-brand text-brand-foreground font-semibold"
                            : "text-foreground hover:bg-muted",
                        )}
                      >
                        {p}
                      </button>
                    ),
                  )}
                  <button
                    type="button"
                    disabled={page >= totalPages}
                    onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                    className="inline-flex size-7 items-center justify-center rounded-md border border-input bg-card text-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
                    aria-label="Next page"
                  >
                    <ChevronRight className="size-4" />
                  </button>
                </div>
              </div>
            )}
          </>
        )}
      </div>

      <EmployeeFormDialog open={dialogOpen} onOpenChange={setDialogOpen} />
    </div>
  );
}
