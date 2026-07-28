"use client";

import { useRef, useState } from "react";
import { User, Briefcase, CreditCard, FileText, Download, Plus, Pencil, Lock, Trash2 } from "lucide-react";

import { cn } from "@/lib/utils";
import { notify as toast } from "@/core/lib/notify";
import { Avatar } from "@/core/components/avatar";
import { StatusBadge } from "@/core/components/status-badge";
import { EmployeeLoans } from "@/modules/hr/components/employee-loans";
import { Button } from "@/components/ui/button";
import { formatPeso, formatDate } from "@/core/lib/format";
import {
  statusLabel,
  payFrequencyLabel,
  salaryChangeLabel,
  type Employee,
  type EmploymentHistoryEntry,
  type MyDocument,
} from "@/modules/hr/types/employee";
import {
  useSalaryHistory,
  useActivateEmployee,
  useSetHrRole,
  useEmployeeDocuments,
  useMyDocuments,
  useUploadEmployeeDocument,
  useDeleteEmployeeDocument,
} from "@/modules/hr/hooks/use-employees";
import { useCreateDocumentRequest } from "@/modules/hr/hooks/use-requests";
import { useMyLeaveSummary } from "@/modules/hr/hooks/use-leave";
import { EmployeeScheduleEditor } from "@/modules/hr/components/employee-schedule-editor";
import { EmployeeLeaveCreditsEditor } from "@/modules/hr/components/employee-leave-credits-editor";
import type { LeaveBalance } from "@/modules/hr/types/leave";
import { DeleteConfirmDialog } from "@/modules/hr/components/delete-confirm-dialog";

export type TabKey = "personal" | "job" | "compensation" | "documents";

const TABS: { key: TabKey; label: string; icon: typeof User }[] = [
  { key: "personal", label: "Personal", icon: User },
  { key: "job", label: "Job", icon: Briefcase },
  { key: "compensation", label: "Compensation", icon: CreditCard },
  { key: "documents", label: "Documents", icon: FileText },
];

// Only these tabs have an editable form; Work Details and Documents don't.
const EDITABLE_TABS = new Set<TabKey>(["personal", "job", "compensation"]);

export function EmployeeRecord({
  employee,
  onEdit,
  onRequestChange,
  onRequestDocument,
}: {
  employee: Employee;
  /** Omit for read-only (employee's own profile). Receives the active tab. */
  onEdit?: (tab: TabKey) => void;
  /** Self-service: open the "request profile change" modal (Personal tab). */
  onRequestChange?: () => void;
  /** Self-service: open the "request a document" modal (Documents tab). */
  onRequestDocument?: () => void;
}) {
  const [tab, setTab] = useState<TabKey>("personal");
  const isHr = !!onEdit;
  const visibleTabs = TABS;

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-[300px_1fr]">
      {/* Left: identity + tab rail */}
      <div className="rounded-card border border-border bg-card p-6">
        <div className="flex flex-col items-center text-center">
          <Avatar name={employee.name} colorSeed={employee.id} size="lg" />
          <p className="mt-3 text-h3 font-bold text-foreground">{employee.name}</p>
          <p className="text-caption text-muted-foreground">
            {employee.job_title}
            {employee.department ? ` · ${employee.department.name}` : ""}
          </p>
          <div className="mt-2">
            {employee.onboarding_status === "pending" ? (
              <StatusBadge status="pending" label="Onboarding" />
            ) : (
              <StatusBadge status="active" label="Active" />
            )}
          </div>
        </div>

        <nav className="mt-6 space-y-1 border-t border-border pt-4">
          {visibleTabs.map(({ key, label, icon: Icon }) => (
            <button
              key={key}
              type="button"
              onClick={() => setTab(key)}
              className={cn(
                "flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors",
                tab === key
                  ? "bg-brand text-brand-foreground"
                  : "text-muted-foreground hover:bg-muted hover:text-foreground",
              )}
            >
              <Icon className="size-[18px]" />
              {label}
            </button>
          ))}
        </nav>
      </div>

      {/* Right: tab content */}
      <div className="rounded-card border border-border bg-card p-6">
        {/* Documents renders its own header (with the Upload button). */}
        {tab !== "documents" && (
          <div className="mb-5 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h2 className="text-h3 font-bold text-foreground">
              {TABS.find((t) => t.key === tab)?.label} Information
            </h2>
            {onEdit && EDITABLE_TABS.has(tab) && (
              <Button variant="link" size="sm" onClick={() => onEdit(tab)}>
                Edit
              </Button>
            )}
            {onRequestChange && tab === "personal" && (
              <Button size="sm" variant="outline" onClick={onRequestChange}>
                <Pencil />
                Request Change
              </Button>
            )}
          </div>
        )}

        {tab === "personal" && (
          <div className="space-y-8">
            <Section title="Personal Information">
              <Detail label="Last name" value={employee.last_name} />
              <Detail label="First name" value={employee.first_name} />
              <Detail label="Middle name" value={employee.middle_name} />
              <Detail label="Name extension" value={employee.name_extension} />
              <Detail label="Contact number" value={employee.phone} />
              <Detail label="Email address" value={employee.email} />
              <Detail label="Gender" value={employee.gender} />
              <Detail label="Civil status" value={employee.civil_status} />
              <Detail label="Nationality" value={employee.nationality} />
              <Detail
                label="Date of birth"
                value={employee.date_of_birth ? formatDate(employee.date_of_birth) : null}
              />
              <Detail label="Place of birth" value={employee.place_of_birth} />
              <Detail label="Home address" value={employee.address} />
              <Detail label="Current address" value={employee.current_address} />
              <Detail label="Mother's maiden name" value={employee.mothers_maiden_name} />
              <Detail label="Father's name" value={employee.fathers_name} />
              <Detail label="Siblings name" value={employee.siblings_name} />
            </Section>

            <Section title="Educational Background" divider>
              <Detail label="High school" value={employee.high_school} />
              <Detail label="College course" value={employee.college_course} />
              <Detail label="University/College" value={employee.university} />
            </Section>

            <Section title="Emergency Contact" divider>
              <Detail label="Full name" value={employee.emergency_contact_name} />
              <Detail label="Relationship" value={employee.emergency_contact_relationship} />
              <Detail label="Contact number" value={employee.emergency_contact_phone} />
            </Section>

            <Section title="Health" divider>
              <Detail label="Height" value={employee.height} />
              <Detail label="Weight" value={employee.weight} />
              <Detail label="Health concern/condition" value={employee.health_condition} />
            </Section>
          </div>
        )}

        {tab === "job" && (
          <div className="space-y-8">
            <div className="grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
              <Detail label="Title" value={employee.job_title} />
              <Detail label="Department" value={employee.department?.name} />
              <Detail label="Manager" value={employee.manager?.name ?? "—"} />
              <Detail
                label="Type"
                value={employee.employment_type ?? statusLabel(employee.employment_status)}
              />
              <Detail label="Location" value={employee.work_location} />
              <Detail label="Start date" value={formatDate(employee.hire_date)} />
              <Detail label="Employee ID number" value={employee.employee_no} />
              <Detail label="Employee role" value={employee.employee_role} />
            </div>

            {/* Work schedule: HR assigns/overrides here (moved off the Rules page). */}
            {isHr && (
              <div className="border-t border-border pt-6">
                <p className="mb-4 text-label font-semibold uppercase tracking-wide text-brand">
                  Work Schedule
                </p>
                <EmployeeScheduleEditor employeeId={employee.id} />
              </div>
            )}

            <div className="border-t border-border pt-6">
              <p className="mb-4 text-label font-semibold uppercase tracking-wide text-brand">
                Employment History
              </p>
              <EmploymentHistoryList entries={employee.employment_history} />
            </div>

            {isHr && employee.onboarding_status === "pending" && (
              <ActivateOnboarding employeeId={employee.id} />
            )}

            {isHr && <HrAccessSection employee={employee} />}
          </div>
        )}

        {tab === "compensation" && (
          <div className="space-y-8">
            <div className="grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
              <Detail
                label="Base salary"
                value={`${formatPeso(employee.base_salary)} / mo`}
              />
              <Detail label="Pay frequency" value={payFrequencyLabel(employee.pay_frequency)} />
              <Detail label="Currency" value={employee.currency} />
              <Detail label="Bank type" value={employee.bank_account_type} />
              <Detail label="Bank account number" value={employee.bank_account} />
              <Detail label="Bank account name" value={employee.bank_account_name} />
              <Detail label="SSS" value={money(employee.sss_contribution)} />
              <Detail label="Pag-IBIG" value={money(employee.pagibig_contribution)} />
              <Detail label="PhilHealth" value={money(employee.philhealth_contribution)} />
              <Detail label="Food allowance" value={money(employee.food_allowance)} />
              <Detail label="Transportation allowance" value={money(employee.transportation_allowance)} />
              <Detail label="Load allowance" value={money(employee.load_allowance)} />
            </div>

            <div className="border-t border-border pt-6">
              <p className="mb-4 text-label font-semibold uppercase tracking-wide text-brand">
                Leave Credits
              </p>
              {/* HR edits this person's credits (source of truth, moved off the
                  Leave Type page); an employee sees their own read-only via "me". */}
              {isHr ? (
                <EmployeeLeaveCreditsEditor employeeId={employee.id} />
              ) : (
                <MyLeaveCredits />
              )}
            </div>

            {isHr && (
              <div className="border-t border-border pt-6">
                <p className="mb-4 text-label font-semibold uppercase tracking-wide text-brand">
                  Compensation History
                </p>
                <CompensationHistory employeeId={employee.id} />
              </div>
            )}

            {/* Slice 11b — loans & cash advances repaid through payroll. */}
            {isHr && (
              <div className="border-t border-border pt-6">
                <p className="mb-4 text-label font-semibold uppercase tracking-wide text-brand">
                  Loans &amp; Cash Advances
                </p>
                <EmployeeLoans employeeId={employee.id} />
              </div>
            )}
          </div>
        )}

        {tab === "documents" &&
          (isHr ? (
            <DocumentsTab employeeId={employee.id} />
          ) : (
            <MyDocuments onRequestDocument={onRequestDocument} />
          ))}
      </div>
    </div>
  );
}

function formatBytes(b: number): string {
  if (b < 1024) return `${b} B`;
  if (b < 1024 * 1024) return `${Math.round(b / 1024)} KB`;
  return `${(b / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * Employee self-service Documents view: HR-uploaded files are LISTED, but downloading
 * one requires requesting access — HR approves, then the download unlocks.
 */
function MyDocuments({ onRequestDocument }: { onRequestDocument?: () => void }) {
  const { data: docs, isLoading } = useMyDocuments();
  const createReq = useCreateDocumentRequest();
  const list = docs ?? [];

  async function requestAccess(doc: MyDocument) {
    try {
      await createReq.mutateAsync({ employee_document_id: doc.id });
      toast.success("Access requested", "HR will approve it, then you can download.");
    } catch {
      toast.error("Could not submit the request.");
    }
  }

  return (
    <div>
      <div className="mb-5 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <h2 className="text-h3 font-bold text-foreground">Documents</h2>
        {onRequestDocument && (
          <Button size="sm" onClick={onRequestDocument}>
            <Plus />
            Request Document
          </Button>
        )}
      </div>

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading your documents…</p>
      ) : list.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          No documents on your record yet. Use{" "}
          <span className="font-medium text-foreground">Request Document</span> to ask HR for one.
        </p>
      ) : (
        <div className="divide-y divide-border rounded-lg border border-border">
          {list.map((d) => (
            <div key={d.id} className="flex items-center justify-between gap-3 px-4 py-3">
              <div className="flex min-w-0 items-center gap-3">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                  {d.unlocked ? <FileText className="size-4" /> : <Lock className="size-4" />}
                </span>
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium text-foreground">{d.name}</p>
                  <p className="text-caption text-muted-foreground">
                    {d.label} · {formatBytes(d.size_bytes)} · {d.created_at}
                  </p>
                </div>
              </div>
              {d.unlocked && d.url ? (
                <Button size="xs" variant="outline" asChild>
                  <a href={d.url} target="_blank" rel="noreferrer">
                    <Download /> Download
                  </a>
                </Button>
              ) : d.request_status === "pending" || d.request_status === "in_progress" ? (
                <span className="text-caption font-medium text-warning">Pending</span>
              ) : (
                <Button
                  size="xs"
                  variant="outline"
                  disabled={createReq.isPending}
                  onClick={() => requestAccess(d)}
                >
                  {d.request_status === "rejected" ? "Request again" : "Request"}
                </Button>
              )}
            </div>
          ))}
        </div>
      )}

      <p className="mt-4 text-caption text-muted-foreground">
        These are the documents HR has on your record. Request the ones you need — HR
        approves each, then it becomes downloadable here.
      </p>
    </div>
  );
}

function DocumentsTab({ employeeId }: { employeeId: number }) {
  const { data: docs, isLoading } = useEmployeeDocuments(employeeId);
  const upload = useUploadEmployeeDocument(employeeId);
  const remove = useDeleteEmployeeDocument(employeeId);
  const fileRef = useRef<HTMLInputElement>(null);
  const [removing, setRemoving] = useState<{ id: number; name: string } | null>(null);

  async function onFile(file?: File) {
    if (!file) return;
    try {
      await upload.mutateAsync({ file });
      toast.success("Document uploaded");
    } catch {
      toast.error("Could not upload the document.");
    }
    if (fileRef.current) fileRef.current.value = ""; // allow re-uploading the same file
  }

  async function performRemove(id: number) {
    try {
      await remove.mutateAsync(id);
      toast.success("Document removed");
    } catch {
      toast.error("Could not remove the document.");
    }
  }

  return (
    <div>
      <div className="mb-5 flex items-center justify-between">
        <h2 className="text-h3 font-bold text-foreground">Documents</h2>
        <input
          ref={fileRef}
          type="file"
          accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
          className="sr-only"
          onChange={(e) => onFile(e.target.files?.[0])}
        />
        <Button size="sm" disabled={upload.isPending} onClick={() => fileRef.current?.click()}>
          <Plus />
          {upload.isPending ? "Uploading…" : "Upload Document"}
        </Button>
      </div>

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : (docs ?? []).length === 0 ? (
        <p className="rounded-card border border-border bg-card px-4 py-8 text-center text-sm text-muted-foreground">
          No documents yet. Upload one to get started.
        </p>
      ) : (
        <div className="divide-y divide-border">
          {(docs ?? []).map((d) => (
            <div key={d.id} className="flex items-center gap-3 py-3">
              <FileText className="size-5 shrink-0 text-muted-foreground" />
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-foreground">{d.name}</p>
                <p className="text-caption text-muted-foreground">
                  {d.label} {formatDate(d.created_at)} · {formatBytes(d.size_bytes)}
                </p>
              </div>
              {d.url && (
                <Button variant="outline" size="sm" asChild>
                  <a href={d.url} download={d.name} target="_blank" rel="noopener noreferrer">
                    <Download />
                    Download
                  </a>
                </Button>
              )}
              <Button
                variant="ghost"
                size="icon-sm"
                disabled={remove.isPending}
                onClick={() => setRemoving({ id: d.id, name: d.name })}
                aria-label={`Remove ${d.name}`}
                title="Remove"
              >
                <Trash2 />
              </Button>
            </div>
          ))}
        </div>
      )}

      <DeleteConfirmDialog
        open={!!removing}
        onOpenChange={(o) => !o && setRemoving(null)}
        title="Remove document"
        question={`Are you sure you want to remove “${removing?.name ?? ""}”?`}
        description="This action cannot be undone. The file will be permanently deleted from this employee's record."
        confirmLabel="Remove"
        isPending={remove.isPending}
        onConfirm={() => performRemove(removing!.id)}
      />
    </div>
  );
}

function ActivateOnboarding({ employeeId }: { employeeId: number }) {
  const activate = useActivateEmployee();
  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between rounded-card border border-warning/40 bg-warning-subtle p-4">
      <div>
        <p className="text-sm font-semibold text-foreground">Onboarding in progress</p>
        <p className="text-xs text-muted-foreground">Mark complete once this hire is fully onboarded.</p>
      </div>
      <Button
        size="sm"
        disabled={activate.isPending}
        onClick={async () => {
          try {
            await activate.mutateAsync(employeeId);
            toast.success("Onboarding complete");
          } catch {
            toast.error("Could not update onboarding.");
          }
        }}
      >
        {activate.isPending ? "Saving…" : "Mark complete"}
      </Button>
    </div>
  );
}

// Format a raw decimal string as peso, or a dash when empty.
function money(v: string | null): string | null {
  return v ? formatPeso(v) : null;
}

/**
 * HR-only "System Access" block (Job tab): grant or revoke HR-officer access.
 * HR officers keep their employee role too — this only toggles the extra keychain.
 */
function HrAccessSection({ employee }: { employee: Employee }) {
  const setHrRole = useSetHrRole(employee.id);
  const isOn = employee.is_hr_officer;

  async function toggle() {
    try {
      await setHrRole.mutateAsync(!isOn);
      toast.success(isOn ? "HR officer access revoked" : "HR officer access granted");
    } catch (e: unknown) {
      // Surface the backend's message (e.g. the "can't revoke your own" guard).
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data
        ?.message;
      toast.error(msg ?? "Could not update access.");
    }
  }

  return (
    <div className="border-t border-border pt-6">
      <p className="mb-1 text-label font-semibold uppercase tracking-wide text-brand">
        System Access
      </p>
      <p className="mb-4 text-caption text-muted-foreground">
        Controls what this person can do in the app.
      </p>
      <div className="flex items-center justify-between gap-4 rounded-card border border-border p-4">
        <div>
          <p className="text-sm font-semibold text-foreground">HR Officer access</p>
          <p className="text-caption text-muted-foreground">
            {isOn
              ? "Can manage HR data for all employees."
              : "Standard employee — self-service access only."}
          </p>
        </div>
        <Button
          variant={isOn ? "outline" : "default"}
          size="sm"
          disabled={setHrRole.isPending}
          onClick={toggle}
        >
          {setHrRole.isPending ? "Saving…" : isOn ? "Revoke access" : "Make HR Officer"}
        </Button>
      </div>
    </div>
  );
}

// An employee viewing their OWN leave credits (the "me" endpoint). In shared
// mode there are no per-type credits — just the single pool.
function MyLeaveCredits() {
  const { data, isLoading } = useMyLeaveSummary();

  if (isLoading) {
    return <p className="text-sm text-muted-foreground">Loading leave credits…</p>;
  }

  if (data?.mode === "shared") {
    const pool = data.pool;
    if (!pool) {
      return (
        <p className="text-sm text-muted-foreground">No shared leave balance for this year.</p>
      );
    }
    return (
      <div className="max-w-sm rounded-card border border-border p-4">
        <div className="flex items-center gap-2">
          <span className="size-2.5 shrink-0 rounded-full bg-brand" />
          <p className="truncate text-sm font-medium text-foreground">Shared Leave Pool</p>
        </div>
        <p className="mt-2 text-h3 font-bold text-foreground">
          {pool.remaining_days}
          <span className="text-sm font-normal text-muted-foreground">
            {" "}
            / {pool.entitled_days} days
          </span>
        </p>
        <p className="text-caption text-muted-foreground">{pool.used_days} used this year</p>
        <p className="mt-2 text-caption text-muted-foreground">
          All leave types draw from this shared pool.
        </p>
      </div>
    );
  }

  return <LeaveCreditsGrid balances={data?.balances} isLoading={false} />;
}

// Shared card grid: one card per leave type showing remaining / entitled days.
function LeaveCreditsGrid({
  balances,
  isLoading,
}: {
  balances?: LeaveBalance[];
  isLoading: boolean;
}) {
  if (isLoading) {
    return <p className="text-sm text-muted-foreground">Loading leave credits…</p>;
  }
  const rows = balances ?? [];
  if (rows.length === 0) {
    return <p className="text-sm text-muted-foreground">No leave credits for this year.</p>;
  }
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {rows.map((b) => (
        <div key={b.id} className="rounded-card border border-border p-4">
          <div className="flex items-center gap-2">
            <span
              className="size-2.5 shrink-0 rounded-full"
              style={{ backgroundColor: b.leave_type.color ?? "#4FDBD7" }}
            />
            <p className="truncate text-sm font-medium text-foreground">{b.leave_type.name}</p>
          </div>
          <p className="mt-2 text-h3 font-bold text-foreground">
            {b.remaining_days}
            <span className="text-sm font-normal text-muted-foreground">
              {" "}
              / {b.entitled_days} days
            </span>
          </p>
          <p className="text-caption text-muted-foreground">{b.used_days} used this year</p>
        </div>
      ))}
    </div>
  );
}

// Compensation history timeline — base salary shown annualised, with a typed badge
// (Promotion/Merit/Hire) and the % change vs the previous (older) entry.
function CompensationHistory({ employeeId }: { employeeId: number }) {
  const { data: history, isLoading } = useSalaryHistory(employeeId);
  if (isLoading) return <p className="text-sm text-muted-foreground">Loading…</p>;
  const rows = history ?? [];
  if (rows.length === 0) {
    return <p className="text-sm text-muted-foreground">No compensation history yet.</p>;
  }

  return (
    <div className="divide-y divide-border">
      {rows.map((h, i) => {
        const prev = rows[i + 1]; // rows are newest-first; the next one is older
        const pct =
          prev && prev.base_salary
            ? Math.round(((h.base_salary - prev.base_salary) / prev.base_salary) * 100)
            : null;
        const isHire = h.change_type === "hire";
        const badge = isHire
          ? "Hire"
          : `${salaryChangeLabel(h.change_type)}${pct != null ? ` ${pct >= 0 ? "+" : ""}${pct}%` : ""}`;
        return (
          <div key={h.id} className="flex items-center justify-between gap-3 py-3">
            <div>
              <p className="text-sm font-semibold text-foreground">{formatPeso(h.base_salary * 12)}</p>
              <p className="text-caption text-muted-foreground">
                Eff. {HISTORY_MONTH(h.effective_date)}
              </p>
            </div>
            <span
              className={cn(
                "shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold uppercase",
                h.change_type === "promotion"
                  ? "bg-success-subtle text-success"
                  : "bg-muted text-muted-foreground",
              )}
            >
              {badge}
            </span>
          </div>
        );
      })}
    </div>
  );
}

const HISTORY_MONTH = (iso: string) =>
  new Date(iso).toLocaleDateString("en-PH", { month: "short", year: "numeric" });

function EmploymentHistoryList({ entries }: { entries: EmploymentHistoryEntry[] }) {
  if (entries.length === 0) {
    return <p className="text-sm text-muted-foreground">No employment history yet.</p>;
  }
  return (
    <div className="divide-y divide-border">
      {entries.map((h) => (
        <div key={h.id} className="flex items-center justify-between gap-3 py-3">
          <div className="min-w-0">
            <p className="text-sm font-semibold text-foreground">{h.title}</p>
            <p className="text-caption text-muted-foreground">
              {HISTORY_MONTH(h.start_date)} – {h.end_date ? HISTORY_MONTH(h.end_date) : "Present"}
            </p>
          </div>
          <span
            className={cn(
              "shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold uppercase",
              h.status === "current"
                ? "bg-success-subtle text-success"
                : "bg-muted text-muted-foreground",
            )}
          >
            {h.status}
          </span>
        </div>
      ))}
    </div>
  );
}

// A titled subsection (teal header) with a 3-column detail grid, matching the
// expanded personal record. `divider` adds a top rule to separate stacked sections.
function Section({
  title,
  divider,
  children,
}: {
  title: string;
  divider?: boolean;
  children: React.ReactNode;
}) {
  return (
    <div className={cn(divider && "border-t border-border pt-6")}>
      <p className="mb-4 text-label font-semibold uppercase tracking-wide text-brand">{title}</p>
      <div className="grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
        {children}
      </div>
    </div>
  );
}

function Detail({ label, value }: { label: string; value?: string | null }) {
  return (
    <div>
      <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
        {label}
      </p>
      <p className="mt-1 text-sm text-foreground">{value || "—"}</p>
    </div>
  );
}
