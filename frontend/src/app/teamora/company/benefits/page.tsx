"use client";

import { useState } from "react";
import { Pencil, Plus } from "lucide-react";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { PageHeader } from "@/core/components/page-header";
import { formatPeso } from "@/core/lib/format";
import { useContributionSettings } from "@/modules/hr/hooks/use-benefits";
import { useBenefitPrograms } from "@/modules/hr/hooks/use-benefit-programs";
import { useEmployees } from "@/modules/hr/hooks/use-employees";
import { AssignBenefitsDialog } from "@/modules/hr/components/assign-benefits-dialog";
import { EditAllowanceDialog } from "@/modules/hr/components/edit-allowance-dialog";
import { EditHmoDialog } from "@/modules/hr/components/edit-hmo-dialog";
import {
  CONTRIBUTION_LABELS,
  type BenefitProgram,
  type ContributionSetting,
} from "@/modules/hr/types/benefits";

// Government dot colours (SSS / PhilHealth / Pag-IBIG) per the design.
const GOV_COLOR: Record<string, string> = {
  sss: "#a855f7",
  philhealth: "#f97316",
  pagibig: "#14b8a6",
};

// Friendly kind label shown under a company-benefit name.
const CATEGORY_LABEL: Record<string, string> = {
  hmo: "Medical",
  retirement: "Retirement",
  insurance: "Insurance",
  general: "Benefit",
};

export default function BenefitsPage() {
  const { data: settings } = useContributionSettings();
  const { data: programs, isLoading, isError } = useBenefitPrograms();
  const { data: employees } = useEmployees();

  const allowances = (programs ?? []).filter((p) => p.kind === "allowance" && p.is_active);
  const company = (programs ?? []).filter((p) => p.kind === "company");
  const activeCount = (programs ?? []).filter((p) => p.is_active).length;
  // Company plans with coverage tiers are assignable as an HMO / health plan.
  const hmoPlans = company.filter((p) => p.tiers.length > 0);

  const [assignOpen, setAssignOpen] = useState(false);
  const [editAllowance, setEditAllowance] = useState<BenefitProgram | null>(null);
  const [editHmo, setEditHmo] = useState<BenefitProgram | null>(null);

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Benefits"
        subtitle={
          programs
            ? `${activeCount} active ${activeCount === 1 ? "plan" : "plans"} · government contributions are system-managed`
            : "Allowances, company benefits, and system-managed government contributions."
        }
        actions={
          <Button onClick={() => setAssignOpen(true)}>
            <Plus />
            Add a Plan
          </Button>
        }
      />

      <div className="mt-8 space-y-10">
        {/* Government mandated — system managed, read-only. */}
        <section className="space-y-3">
          <div className="flex items-center gap-2">
            <h2 className="text-h3 font-bold text-foreground">Government mandated</h2>
            <span className="rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold text-muted-foreground">
              System managed
            </span>
          </div>
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {(settings ?? []).map((s) => (
              <GovCard key={s.id} setting={s} />
            ))}
          </div>
        </section>

        {/* Allowances — HR editable. */}
        <section className="space-y-3">
          <h2 className="text-h3 font-bold text-foreground">Allowances</h2>
          {isLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : isError ? (
            <p className="text-sm text-destructive">Could not load benefits. Please refresh.</p>
          ) : allowances.length === 0 ? (
            <EmptyHint text="No allowances yet. Use “Add a Plan” to assign one." />
          ) : (
            <div className="grid gap-4 md:grid-cols-2">
              {allowances.map((p) => (
                <AllowanceCard key={p.id} program={p} onEdit={() => setEditAllowance(p)} />
              ))}
            </div>
          )}
        </section>

        {/* Company benefits — HR editable. */}
        <section className="space-y-3">
          <h2 className="text-h3 font-bold text-foreground">Company benefits</h2>
          {isLoading ? null : company.length === 0 ? (
            <EmptyHint text="No company benefits yet." />
          ) : (
            <div className="space-y-4">
              {company.map((p) => (
                <CompanyBenefitCard key={p.id} program={p} onEdit={() => setEditHmo(p)} />
              ))}
            </div>
          )}
        </section>
      </div>

      <AssignBenefitsDialog
        open={assignOpen}
        onOpenChange={setAssignOpen}
        employees={employees ?? []}
        allowances={allowances}
        hmoPlans={hmoPlans}
      />
      <EditAllowanceDialog
        program={editAllowance}
        onOpenChange={(o) => !o && setEditAllowance(null)}
      />
      <EditHmoDialog program={editHmo} onOpenChange={(o) => !o && setEditHmo(null)} />
    </div>
  );
}

// A government contribution — read-only, shows the statutory rate (system managed).
function GovCard({ setting }: { setting: ContributionSetting }) {
  const rate = Number(setting.config.rate ?? 0);
  const employer = Number(setting.config.employer_rate ?? 0);
  const label = CONTRIBUTION_LABELS[setting.type] ?? setting.type;

  return (
    <div className="rounded-card border border-border bg-card p-5">
      <div className="flex items-center gap-2.5">
        <Dot color={GOV_COLOR[setting.type] ?? "#94a3b8"} />
        <div>
          <p className="font-bold text-foreground">{label}</p>
          <p className="text-xs text-muted-foreground">Government · Mandatory</p>
        </div>
      </div>
      <p className="mt-4 text-3xl font-bold text-foreground">{formatPct(rate)}</p>
      <p className="mt-1 text-xs text-muted-foreground">
        Employee share{employer ? ` · Employer ${formatPct(employer)}` : ""} · statutory table
      </p>
    </div>
  );
}

// A monthly allowance — HR editable, shows the peso amount added to payroll.
function AllowanceCard({ program, onEdit }: { program: BenefitProgram; onEdit: () => void }) {
  return (
    <div className="rounded-card border border-border bg-card p-5">
      <div className="flex items-start justify-between gap-2">
        <div className="flex items-center gap-2.5">
          <Dot color={program.color ?? "#3b82f6"} />
          <div>
            <p className="font-bold text-foreground">{program.name}</p>
            <p className="text-xs text-muted-foreground">Allowance · Monthly</p>
          </div>
        </div>
        <EditButton onClick={onEdit} />
      </div>
      <p className="mt-4 text-3xl font-bold text-foreground">
        {program.amount != null ? formatPeso(program.amount) : "—"}
      </p>
      <p className="mt-1 text-xs text-muted-foreground">Per month · added to payroll</p>
    </div>
  );
}

// A company benefit (HMO, retirement, …) — HR editable, full width.
function CompanyBenefitCard({ program, onEdit }: { program: BenefitProgram; onEdit: () => void }) {
  const isDraft = /soon|draft/i.test(program.status);

  return (
    <div className="rounded-card border border-border bg-card p-5">
      <div className="flex items-start justify-between gap-2">
        <div className="flex items-start gap-2.5">
          <Dot color={program.color ?? "#ef4444"} />
          <div>
            <div className="flex items-center gap-2">
              <p className="font-bold text-foreground">{program.name}</p>
              <StatusBadge status={program.status} />
            </div>
            <p className="text-xs text-muted-foreground">
              {CATEGORY_LABEL[program.category] ?? "Benefit"}
            </p>
          </div>
        </div>
        <EditButton onClick={onEdit} />
      </div>

      {program.description && (
        <p className="mt-3 text-sm text-muted-foreground">{program.description}</p>
      )}

      {isDraft && (
        <Button variant="outline" size="sm" className="mt-4" onClick={onEdit}>
          Set up as draft
        </Button>
      )}
    </div>
  );
}

function EditButton({ onClick }: { onClick: () => void }) {
  return (
    <Button size="icon-sm" variant="ghost" onClick={onClick}>
      <Pencil />
      <span className="sr-only">Edit</span>
    </Button>
  );
}

function Dot({ color }: { color: string }) {
  return (
    <span className="mt-1 size-3 shrink-0 rounded-full" style={{ backgroundColor: color }} />
  );
}

function StatusBadge({ status }: { status: string }) {
  const s = status.toLowerCase();
  const neutral = !/active|enrolled|soon|draft/.test(s);
  return (
    <span
      className={cn(
        "rounded-full px-2 py-0.5 text-xs font-semibold",
        neutral ? "bg-muted text-muted-foreground" : "bg-success-subtle text-success",
      )}
    >
      {status}
    </span>
  );
}

function EmptyHint({ text }: { text: string }) {
  return (
    <div className="rounded-card border border-dashed border-border bg-card p-4 sm:p-8 text-center text-sm text-muted-foreground">
      {text}
    </div>
  );
}

// 0.045 → "4.5%"
function formatPct(rate: number): string {
  const pct = rate * 100;
  return `${Number.isInteger(pct) ? pct : Number(pct.toFixed(2))}%`;
}
