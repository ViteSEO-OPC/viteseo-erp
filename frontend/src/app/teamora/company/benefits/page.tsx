"use client";

import { useState } from "react";
import { Pencil, Plus } from "lucide-react";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { PageHeader } from "@/modules/hr/components/page-header";
import { formatPeso, formatDate } from "@/core/lib/format";
import { notify as toast } from "@/core/lib/notify";
import { usePayrollSettings, useUpdatePayrollSettings } from "@/modules/hr/hooks/use-payroll";
import { useContributionSettings } from "@/modules/hr/hooks/use-benefits";
import { useBenefitPrograms } from "@/modules/hr/hooks/use-benefit-programs";
import { useEmployees } from "@/modules/hr/hooks/use-employees";
import { AssignBenefitsDialog } from "@/modules/hr/components/assign-benefits-dialog";
import { EditAllowanceDialog } from "@/modules/hr/components/edit-allowance-dialog";
import { EditContributionDialog } from "@/modules/hr/components/edit-contribution-dialog";
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
  const [editContribution, setEditContribution] = useState<string | null>(null);
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
              <GovCard key={s.id} setting={s} onEdit={() => setEditContribution(s.type)} />
            ))}
          </div>
        </section>

        {/* Allowances — HR editable. */}
        <section className="space-y-3">
          <h2 className="text-h3 font-bold text-foreground">Allowances</h2>

          {/* The tax-free ceiling belongs with the allowances it governs, not on the payroll
              page. It is stored on the payroll settings because that is what payroll reads. */}
          <DeMinimisCeiling />
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
      <EditContributionDialog
        type={editContribution}
        onOpenChange={(o) => !o && setEditContribution(null)}
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
function GovCard({ setting, onEdit }: { setting: ContributionSetting; onEdit: () => void }) {
  // Slice B3a/b — the version in force, NOT the legacy `config`. Reading `config` here meant the
  // card showed 4.5% while payroll charged 5% after a rate change.
  const current = setting.current;
  const rate = Number(current?.employee_rate ?? 0);
  const employer = Number(current?.employer_rate ?? 0);
  const label = CONTRIBUTION_LABELS[setting.type] ?? setting.type;

  // How it's charged, in one line — so the card explains itself without opening the dialog.
  const basis =
    current?.basis === "bracket"
      ? `${current.bracket_count} salary brackets`
      : current?.employee_amount_cap
        ? `max ${formatPeso(current.employee_amount_cap)}/mo`
        : current?.salary_cap
          ? `up to ${formatPeso(current.salary_cap)} salary`
          : "uncapped";

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
        Employee{employer ? ` · Employer ${formatPct(employer)}` : ""} · {basis}
      </p>
      {current && (
        <p className="mt-0.5 text-xs text-muted-foreground">
          In force since {formatDate(current.effective_from)}
          {current.fixed_employer_add_on > 0 &&
            ` · +${formatPeso(current.fixed_employer_add_on)} employer`}
        </p>
      )}
      {/* Slice B3a — these rates change by legislation; each change is dated so old payslips
          keep the figures that applied to them. */}
      <Button size="sm" variant="outline" className="mt-4" onClick={onEdit}>
        <Pencil />
        Update rates
      </Button>
    </div>
  );
}

/**
 * How much allowance stays tax-free in a year. Above it, the excess is taxed as compensation
 * (Slice 10). Blank = no ceiling, which is the behaviour if HR never sets one.
 *
 * Saved onto the payroll settings — that is the record payroll actually reads — but it is
 * surfaced here because it is a rule ABOUT allowances, and this is where HR manages them.
 */
function DeMinimisCeiling() {
  const { data: settings } = usePayrollSettings();
  const updateMut = useUpdatePayrollSettings();

  async function save(next: number | null) {
    if (!settings) return;
    try {
      await updateMut.mutateAsync({
        frequency: settings.frequency,
        deduction_timing: settings.deduction_timing,
        tax_timing: settings.tax_timing,
        deminimis_annual_cap: next,
      });
      toast.success(next === null ? "Ceiling removed" : "Tax-free ceiling updated");
    } catch {
      toast.error("Could not update the ceiling.");
    }
  }

  return (
    <div className="rounded-card border border-border bg-card p-4">
      <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
        Tax-free ceiling (per year)
      </p>
      <input
        type="number"
        min={0}
        step="0.01"
        inputMode="decimal"
        defaultValue={settings?.deminimis_annual_cap ?? ""}
        disabled={!settings || updateMut.isPending}
        placeholder="No ceiling — allowances are fully tax-free"
        className="mt-1.5 h-9 w-full max-w-sm rounded-lg border border-input bg-transparent px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
        onBlur={(e) => {
          const raw = e.target.value.trim();
          const next = raw === "" ? null : Number(raw);
          if (next !== (settings?.deminimis_annual_cap ?? null)) {
            save(next);
          }
        }}
      />
      <p className="mt-2 text-caption text-muted-foreground">
        Allowances marked <strong>tax-free</strong> below stay untaxed up to this much a year;
        anything above it is taxed as compensation on the payslip. Allowances marked{" "}
        <strong>taxable</strong> are compensation in full and ignore this ceiling. Leave blank for
        no ceiling. The BIR reference figure is ₱90,000, but the right number for your mix of
        benefits is yours to set.
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
            <p className="text-xs text-muted-foreground">
              Allowance · Monthly ·{" "}
              {/* Slice B4 — this switch now changes withholding, so it has to be visible without
                  opening each allowance in turn. */}
              {program.tax_treatment === "taxable" ? (
                <span className="font-medium text-warning">Taxable</span>
              ) : (
                "Tax-free"
              )}
            </p>
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
