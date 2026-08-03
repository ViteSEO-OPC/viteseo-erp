"use client";

import { useState } from "react";
import { Pencil, Plus } from "lucide-react";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { PageHeader } from "@/modules/hr/components/page-header";
import { formatPeso, formatDate } from "@/core/lib/format";
import { statusLabel } from "@/modules/hr/types/employee";
import { notify as toast } from "@/core/lib/notify";
import { usePayrollSettings, useUpdatePayrollSettings } from "@/modules/hr/hooks/use-payroll";
import { useContributionSettings } from "@/modules/hr/hooks/use-benefits";
import { useBenefitPrograms } from "@/modules/hr/hooks/use-benefit-programs";
import { AllowanceFormDialog } from "@/modules/hr/components/allowance-form-dialog";
import { EditContributionDialog } from "@/modules/hr/components/edit-contribution-dialog";
import { EditHmoDialog } from "@/modules/hr/components/edit-hmo-dialog";
import { PlanEnrollmentDialog } from "@/modules/hr/components/plan-enrollment-dialog";
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

  // Everything the API returns is live: a programme is switched off by deleting it, and the
  // endpoint never returns a deleted one (B13 item 4). The two lists used to disagree about that
  // — allowances were filtered by `is_active` and company plans were not.
  const allowances = (programs ?? []).filter((p) => p.kind === "allowance");
  const company = (programs ?? []).filter((p) => p.kind === "plan");
  const activeCount = (programs ?? []).length;

  // One dialog for add and edit — `null` means a new allowance. "Add a Plan" was removed on
  // 2026-07-29: a two-tab modal that assigned allowances, created plans inline AND enrolled people
  // in an HMO was doing too many unrelated jobs to be understood at a glance.
  const [allowanceOpen, setAllowanceOpen] = useState(false);
  const [editAllowance, setEditAllowance] = useState<BenefitProgram | null>(null);
  const [editContribution, setEditContribution] = useState<string | null>(null);
  const [editHmo, setEditHmo] = useState<BenefitProgram | null>(null);
  // Slice B2 — who is ON a plan is a separate job from what the plan IS, and conflating the two
  // is what made "Add a Plan" impossible to understand.
  const [membersOf, setMembersOf] = useState<BenefitProgram | null>(null);

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Benefits"
        subtitle={
          programs
            ? `${activeCount} active ${activeCount === 1 ? "plan" : "plans"} · government contributions are system-managed`
            : "Allowances, company benefits, and system-managed government contributions."
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
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-h3 font-bold text-foreground">Allowances</h2>
            <Button
              size="sm"
              onClick={() => {
                setEditAllowance(null);
                setAllowanceOpen(true);
              }}
            >
              <Plus />
              Add allowance
            </Button>
          </div>

          {/* The tax-free ceiling belongs with the allowances it governs, not on the payroll
              page. It is stored on the payroll settings because that is what payroll reads. */}
          <DeMinimisCeiling />
          {isLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : isError ? (
            <p className="text-sm text-destructive">Could not load benefits. Please refresh.</p>
          ) : allowances.length === 0 ? (
            <EmptyHint text="No allowances yet. Use “Add allowance” to create one." />
          ) : (
            <div className="grid gap-4 md:grid-cols-2">
              {allowances.map((p) => (
                <AllowanceCard
                  key={p.id}
                  program={p}
                  onEdit={() => {
                    setEditAllowance(p);
                    setAllowanceOpen(true);
                  }}
                />
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
                <CompanyBenefitCard
                  key={p.id}
                  program={p}
                  onEdit={() => setEditHmo(p)}
                  onMembers={() => setMembersOf(p)}
                />
              ))}
            </div>
          )}
        </section>
      </div>

      <EditContributionDialog
        type={editContribution}
        onOpenChange={(o) => !o && setEditContribution(null)}
      />

      <AllowanceFormDialog
        open={allowanceOpen}
        program={editAllowance}
        onOpenChange={(o) => {
          setAllowanceOpen(o);
          if (!o) setEditAllowance(null);
        }}
      />
      <EditHmoDialog program={editHmo} onOpenChange={(o) => !o && setEditHmo(null)} />
      <PlanEnrollmentDialog
        program={membersOf}
        onOpenChange={(o) => !o && setMembersOf(null)}
      />
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
        other_benefits_annual_cap: next,
      });
      toast.success(next === null ? "Ceiling removed" : "Tax-free ceiling updated");
    } catch {
      toast.error("Could not update the ceiling.");
    }
  }

  return (
    <div className="rounded-card border border-border bg-card p-4">
      <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
        13th month pay &amp; other benefits — tax-free ceiling (per year)
      </p>
      <input
        type="number"
        min={0}
        step="0.01"
        inputMode="decimal"
        defaultValue={settings?.other_benefits_annual_cap ?? ""}
        disabled={!settings || updateMut.isPending}
        placeholder="₱90,000 — the statutory figure"
        className="mt-1.5 h-9 w-full max-w-sm rounded-lg border border-input bg-transparent px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
        onBlur={(e) => {
          const raw = e.target.value.trim();
          const next = raw === "" ? null : Number(raw);
          if (next !== (settings?.other_benefits_annual_cap ?? null)) {
            save(next);
          }
        }}
      />
      {/* Slice B16 — this box used to be labelled the de-minimis cap and applied to the total of
          every tax-free allowance. That is two different rules run together: each de-minimis
          benefit has its OWN ceiling (set on the allowance itself), and ₱90,000 is the separate
          exemption covering 13th month pay and other benefits. */}
      <p className="mt-2 text-caption text-muted-foreground">
        The statutory <strong>₱90,000</strong> covers 13th month pay, bonuses, and anything an
        allowance pays <em>above its own de-minimis ceiling</em>. Each allowance carries that
        ceiling itself — set it when you add or edit one. Only what exceeds ₱90,000 in a year is
        taxed as compensation. Allowances marked <strong>taxable</strong> are compensation in full
        and never touch this.
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
      <p className="mt-1 text-xs text-muted-foreground">
        Per month · added to payroll · {eligibilitySummary(program)}
      </p>
    </div>
  );
}

// A company benefit (HMO, retirement, …) — HR editable, full width.
function CompanyBenefitCard({
  program,
  onEdit,
  onMembers,
}: {
  program: BenefitProgram;
  onEdit: () => void;
  onMembers: () => void;
}) {
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

      {/* Slice B2 — a plan nobody can be put on is a brochure. This is the way on. */}
      <div className="mt-4 flex flex-wrap gap-2">
        <Button variant="outline" size="sm" onClick={onMembers}>
          Who is on it
        </Button>
        {isDraft && (
          <Button variant="outline" size="sm" onClick={onEdit}>
            Set up as draft
          </Button>
        )}
      </div>
    </div>
  );
}

/**
 * Who the allowance is for, in one line. The count is computed from the rule server-side, so a
 * status change or a new hire is reflected without anyone re-saving the allowance.
 */
function eligibilitySummary(program: BenefitProgram): string {
  const n = program.covered_count;
  const who =
    program.eligibility === "status"
      ? program.eligibility_statuses.map(statusLabel).join(", ")
      : program.eligibility === "management"
        ? "management"
        : program.eligibility === "custom"
          ? "specific employees"
          : "everyone";

  return `${who} (${n} ${n === 1 ? "person" : "people"})`;
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
