"use client";

import { useRef, useState } from "react";
import {
  User,
  Briefcase,
  CreditCard,
  FileText,
  Download,
  Plus,
  Pencil,
  Lock,
  Trash2,
  Camera,
} from "lucide-react";

import { cn } from "@/lib/utils";
import { notify as toast } from "@/core/lib/notify";
import { StatusBadge } from "@/core/components/status-badge";
import { EmployeeLoans } from "@/modules/hr/components/employee-loans";
import { EmployeeAvatar } from "@/modules/hr/ui/employee-avatar";
import { EmployeePersonalSection } from "@/modules/hr/components/employee-personal-section";
import { EmployeeJobSection } from "@/modules/hr/components/employee-job-section";
import { EmploymentLifecycleSection } from "@/modules/hr/components/employment-lifecycle-section";
import { EmployeeCompensationSection } from "@/modules/hr/components/employee-compensation-section";
import { Button } from "@/components/ui/button";
import { formatPeso, formatDate } from "@/core/lib/format";
import {
  salaryChangeLabel,
  type Employee,
  type EmploymentHistoryEntry,
  type MyDocument,
} from "@/modules/hr/types/employee";
import {
  useSalaryHistory,
  useActivateEmployee,
  useReopenOnboarding,
  useSetHrRole,
  useEmployeeDocuments,
  useMyDocuments,
  useUploadEmployeeDocument,
  useDeleteEmployeeDocument,
  useUploadEmployeePhoto,
  useDeleteEmployeePhoto,
} from "@/modules/hr/hooks/use-employees";
import { useCreateDocumentRequest } from "@/modules/hr/hooks/use-requests";
import { useMyLeaveSummary } from "@/modules/hr/hooks/use-leave";
import { EmployeeScheduleEditor } from "@/modules/hr/components/employee-schedule-editor";
import { EmployeeLeaveCreditsEditor } from "@/modules/hr/components/employee-leave-credits-editor";
import type { LeaveBalance } from "@/modules/hr/types/leave";
import { DeleteConfirmDialog } from "@/modules/hr/components/delete-confirm-dialog";
import { ReopenAction } from "@/modules/hr/components/reopen-action";

export type TabKey = "personal" | "job" | "compensation" | "documents";

const TABS: { key: TabKey; label: string; icon: typeof User }[] = [
  { key: "personal", label: "Personal", icon: User },
  { key: "job", label: "Job", icon: Briefcase },
  { key: "compensation", label: "Compensation", icon: CreditCard },
  { key: "documents", label: "Documents", icon: FileText },
];

export function EmployeeRecord({
  employee,
  isHr = false,
  onRequestChange,
  onRequestDocument,
}: {
  employee: Employee;
  /** HR view: sections are editable in place and the photo can be changed. */
  isHr?: boolean;
  /** Self-service: open the "request profile change" modal (Personal tab). */
  onRequestChange?: () => void;
  /** Self-service: open the "request a document" modal (Documents tab). */
  onRequestDocument?: () => void;
}) {
  const [tab, setTab] = useState<TabKey>("personal");
  const visibleTabs = TABS;

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-[300px_1fr]">
      {/* Left: identity + tab rail */}
      <div className="rounded-card border border-border bg-card p-6">
        <div className="flex flex-col items-center text-center">
          <ProfilePhoto employee={employee} canEdit={isHr} />
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

      {/* Right: tab content. Each editable tab owns its own form + Save/Cancel. */}
      <div className="rounded-card border border-border bg-card p-6">
        {tab === "personal" && (
          <EmployeePersonalSection
            employee={employee}
            canEdit={isHr}
            headerExtra={
              onRequestChange && (
                <Button size="sm" variant="outline" onClick={onRequestChange}>
                  <Pencil />
                  Request Change
                </Button>
              )
            }
          />
        )}

        {tab === "job" && (
          <EmployeeJobSection employee={employee} canEdit={isHr}>
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

            {isHr && <OnboardingPanel employee={employee} />}

            {isHr && <HrAccessSection employee={employee} />}

            {/* MF-18 — separate / reinstate / rehire. On the Job tab because tenure and the last
                working day are job facts, and next to Employment History for the same reason. */}
            {isHr && (
              <div className="border-t border-border pt-6">
                <EmploymentLifecycleSection employee={employee} />
              </div>
            )}
          </EmployeeJobSection>
        )}

        {tab === "compensation" && (
          <EmployeeCompensationSection employee={employee} canEdit={isHr}>
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
          </EmployeeCompensationSection>
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

/**
 * The record's avatar. For HR it doubles as the upload control: click the photo (or the
 * button) to pick a new one, with a Remove action once a photo exists.
 */
function ProfilePhoto({ employee, canEdit }: { employee: Employee; canEdit: boolean }) {
  const fileRef = useRef<HTMLInputElement>(null);
  const upload = useUploadEmployeePhoto(employee.id);
  const remove = useDeleteEmployeePhoto(employee.id);
  const busy = upload.isPending || remove.isPending;

  async function onFile(file?: File) {
    if (!file) return;
    try {
      await upload.mutateAsync(file);
      toast.success("Photo updated");
    } catch (err) {
      // The server explains an oversized or non-image file better than we can.
      const message =
        (err as { response?: { data?: { errors?: { photo?: string[] }; message?: string } } })
          ?.response?.data?.errors?.photo?.[0] ?? "Could not upload the photo.";
      toast.error(message);
    }
    if (fileRef.current) fileRef.current.value = ""; // allow re-picking the same file
  }

  async function onRemove() {
    try {
      await remove.mutateAsync();
      toast.success("Photo removed");
    } catch {
      toast.error("Could not remove the photo.");
    }
  }

  const avatar = (
    <EmployeeAvatar
      name={employee.name}
      photoUrl={employee.photo_url}
      colorSeed={employee.id}
      size="lg"
    />
  );

  if (!canEdit) return avatar;

  return (
    <div className="flex flex-col items-center gap-2">
      <input
        ref={fileRef}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        className="sr-only"
        onChange={(e) => onFile(e.target.files?.[0])}
      />
      <button
        type="button"
        disabled={busy}
        onClick={() => fileRef.current?.click()}
        title="Change profile photo"
        className="group relative rounded-full disabled:opacity-60"
      >
        {avatar}
        <span className="absolute inset-0 flex items-center justify-center rounded-full bg-foreground/60 opacity-0 transition-opacity group-hover:opacity-100">
          <Camera className="size-6 text-white" />
        </span>
      </button>
      <div className="flex items-center gap-1">
        <Button variant="link" size="sm" disabled={busy} onClick={() => fileRef.current?.click()}>
          {upload.isPending ? "Uploading…" : employee.photo_url ? "Change photo" : "Add photo"}
        </Button>
        {employee.photo_url && (
          <Button variant="link" size="sm" disabled={busy} onClick={onRemove}>
            {remove.isPending ? "Removing…" : "Remove"}
          </Button>
        )}
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

/**
 * The onboarding mark and its undo. Shown in both states so completing it by mistake
 * isn't a one-way door — nothing else keys off the flag, so this is a safe flip.
 */
function OnboardingPanel({ employee }: { employee: Employee }) {
  const activate = useActivateEmployee();
  const reopen = useReopenOnboarding();

  if (employee.onboarding_status !== "pending") {
    return (
      <div className="flex flex-col gap-3 rounded-card border border-border p-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <p className="text-sm font-semibold text-foreground">Onboarding complete</p>
          <p className="text-xs text-muted-foreground">
            Reopen it if this hire still has onboarding steps left.
          </p>
        </div>
        <ReopenAction
          label="Reopen onboarding"
          successMessage="Back to onboarding"
          subject={`${employee.name}'s onboarding`}
          description={`${employee.name} goes back to onboarding.`}
          consequence="Their record shows the Onboarding tag again until you mark it complete."
          onReopen={(reason) => reopen.mutateAsync({ id: employee.id, reason })}
        />
      </div>
    );
  }

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
            await activate.mutateAsync(employee.id);
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