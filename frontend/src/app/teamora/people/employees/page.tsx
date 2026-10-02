"use client";

import { useState } from "react";
import { Plus, Search, ChevronLeft, ChevronRight } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import { PageHeader } from "@/modules/hr/components/page-header";
import { EmployeeCard } from "@/modules/hr/components/employee-card";
import { EmployeeFormDialog } from "@/modules/hr/components/employee-form-dialog";
import { useEmployeeDirectory } from "@/modules/hr/hooks/use-employees";
import { useDebouncedValue } from "@/core/use-debounced-value";
import { useDepartments } from "@/modules/hr/hooks/use-departments";
import {
  EMPLOYMENT_STATUSES,
  WORK_STATUSES,
  statusLabel,
  workStatusLabel,
} from "@/modules/hr/types/employee";
import { SimpleSelect } from "@/modules/hr/ui/select";

// Fixed page size (12 fills the 1/2/3-column card grid evenly). Deliberately not
// user-selectable — see the note in DataTable.
const PAGE_SIZE = 12;

export default function EmployeesPage() {
  // Which roster to fetch. A former employee is soft-deleted, so the default query cannot
  // return them at all and there was no way to find somebody to rehire. Separate from the
  // `status` filter, which is the employment TYPE (regular / probationary / …) and a different
  // question entirely — as is `workStatus` (active / floating / suspended).
  const [roster, setRoster] = useState<"active" | "former" | "all">("active");
  const { data: departments } = useDepartments();

  const [search, setSearch] = useState("");
  const [dept, setDept] = useState("all");
  const [status, setStatus] = useState("all");
  const [workStatus, setWorkStatus] = useState("all");
  const [dialogOpen, setDialogOpen] = useState(false);
  const [page, setPage] = useState(1);

  // The box stays responsive; the request waits for the typing to settle.
  const debouncedSearch = useDebouncedValue(search, 250);

  // Every filter below goes to the DATABASE. This page used to receive the first hundred
  // employees and do the searching, the department filter and the paging itself — so the
  // hundred-and-first person was unreachable by any route, searching for them answered "no
  // employees match your filters" rather than admitting it had only looked at a hundred, and
  // the header counted the download rather than the company.
  const { data, isLoading, isError, isFetching } = useEmployeeDirectory({
    search: debouncedSearch.trim() || undefined,
    status: roster,
    department: dept,
    employment_status: status,
    work_status: workStatus,
    page,
    per_page: PAGE_SIZE,
  });

  const paged = data?.items ?? [];
  const total = data?.total ?? 0;
  const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
  // The data can shrink under a reader who is deep in it. Clamping what the pager SHOWS keeps
  // its arrows pointing somewhere real; every filter below already returns them to page 1.
  const currentPage = Math.min(Math.max(1, page), totalPages);
  const pagesToShow: (number | "ellipsis")[] = (() => {
    const result: (number | "ellipsis")[] = [];
    for (let i = 1; i <= totalPages; i++) {
      if (i === 1 || i === totalPages || (i >= currentPage - 1 && i <= currentPage + 1)) {
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
          data ? `Showing ${paged.length} of ${total} employees` : undefined
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
          className="w-48"
          value={dept}
          onValueChange={(v) => { setDept(v); setPage(1); }}
          options={[
            { value: "all", label: "All Departments" },
            ...(departments ?? []).map((d) => ({ value: String(d.id), label: d.name })),
          ]}
        />
        <SimpleSelect
          className="w-52"
          value={status}
          onValueChange={(v) => { setStatus(v); setPage(1); }}
          options={[
            { value: "all", label: "All Employment Types" },
            ...EMPLOYMENT_STATUSES.map((s) => ({ value: s, label: statusLabel(s) })),
          ]}
        />
        <SimpleSelect
          className="w-44"
          value={workStatus}
          onValueChange={(v) => { setWorkStatus(v); setPage(1); }}
          options={[
            { value: "all", label: "All Statuses" },
            ...WORK_STATUSES.map((s) => ({ value: s, label: workStatusLabel(s) })),
          ]}
        />
        {/* Former staff are archived, so finding one to rehire needs its own switch. */}
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
            <div
              className={cn(
                "grid grid-cols-1 gap-3 transition-opacity md:grid-cols-2 xl:grid-cols-3",
                // Dimmed rather than emptied: a page swap replaces every card, and blanking the
                // grid for the round trip reads as the directory vanishing.
                isFetching && "pointer-events-none opacity-60",
              )}
            >
              {paged.map((e) => (
                <EmployeeCard key={e.id} employee={e} />
              ))}
            </div>

            {/* Pagination */}
            {totalPages > 1 && (
              <div className="mt-6 flex flex-wrap items-center justify-between gap-3">
                <div className="text-caption text-muted-foreground">
                  Showing {(currentPage - 1) * PAGE_SIZE + 1}–
                  {Math.min(currentPage * PAGE_SIZE, total)} of {total}
                </div>
                <div className="flex items-center gap-1">
                  <button
                    type="button"
                    disabled={currentPage <= 1 || isFetching}
                    onClick={() => setPage(Math.max(1, currentPage - 1))}
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
                          p === currentPage
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
                    disabled={currentPage >= totalPages || isFetching}
                    onClick={() => setPage(Math.min(totalPages, currentPage + 1))}
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
