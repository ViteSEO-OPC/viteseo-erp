"use client";

import { useState } from "react";
import { AxiosError } from "axios";
import { UserPlus } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { formatPeso, formatDate } from "@/core/lib/format";
import { notify as toast } from "@/core/lib/notify";
import {
  usePlanEnrollments,
  useUpdatePlanEnrollments,
  useEndPlanEnrollment,
} from "@/modules/hr/hooks/use-benefit-programs";
import type { BenefitProgram, PlanEnrollment } from "@/modules/hr/types/benefits";

/**
 * Who is on a company plan, at what tier, paying what — Slice B2.
 *
 * WHY IT HAD TO BE BUILT. Deleting "Add a Plan" (2026-07-29) removed the only screen that could
 * enrol anybody in a plan or set their share. The deduction engine has worked since Slice 11a and
 * could never fire: the HMO is published with three tiers and a per-cutoff cadence, and has nobody
 * on it. A plan that cannot be joined is a brochure.
 *
 * MEMBERSHIP IS AN EXPLICIT LIST HERE, unlike an allowance. An allowance is the same for everyone
 * it covers, so a rule can decide who gets it; a plan carries a per-person tier and share that no
 * rule can derive. The eligibility rule still gates who MAY be added.
 */
export function PlanEnrollmentDialog({
  program,
  onOpenChange,
}: {
  program: BenefitProgram | null;
  onOpenChange: (open: boolean) => void;
}) {
  return (
    <Dialog open={!!program} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-2xl">
        {program && <Members key={program.id} program={program} />}
      </DialogContent>
    </Dialog>
  );
}

function Members({ program }: { program: BenefitProgram }) {
  const { data, isLoading } = usePlanEnrollments(program.id);
  const save = useUpdatePlanEnrollments(program.id);
  const end = useEndPlanEnrollment(program.id);

  const [adding, setAdding] = useState<number | "">("");
  const [tier, setTier] = useState("");

  const tiers = data?.meta.tiers ?? [];
  const enrolled = data?.data.enrolled ?? [];
  const eligible = data?.data.eligible ?? [];
  const active = enrolled.filter((e) => e.ended_at === null);

  async function add() {
    if (adding === "") return;
    try {
      const res = await save.mutateAsync([
        { employee_id: Number(adding), tier: tier || null },
      ]);
      // Rejections come back per row rather than as a failed call, so they have to be shown.
      if (res.rejected?.length) {
        toast.error(res.rejected[0]);
      } else {
        toast.success("Enrolled");
      }
      setAdding("");
      setTier("");
    } catch (err) {
      const errors = err instanceof AxiosError ? err.response?.data?.errors : null;
      toast.error(errors ? String(Object.values(errors)[0]) : "Could not enrol.");
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>{program.name} — who is on it</DialogTitle>
        <DialogDescription>
          {program.deduction_cadence
            ? `Each person's share is deducted ${program.deduction_cadence === "per_cutoff" ? "every cutoff" : "monthly"}.`
            : "This plan has no payroll deduction — nothing is taken from anyone's pay."}
        </DialogDescription>
      </DialogHeader>

      {/* The combination that looks configured and takes nothing. */}
      {data?.meta.deducts_but_no_shares && (
        <div className="rounded-lg border border-warning/40 bg-warning-subtle px-3 py-2.5 text-xs text-foreground">
          <strong>This plan deducts, but nobody has a share set.</strong> As it stands, no money
          comes off any payslip. Set a share — or pick a tier, which fills one in.
        </div>
      )}

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : (
        <div className="space-y-4">
          <div className="space-y-2">
            {active.length === 0 ? (
              <p className="rounded-lg border border-dashed border-border px-3 py-4 text-center text-sm text-muted-foreground">
                Nobody is on this plan yet.
              </p>
            ) : (
              active.map((row) => (
                <MemberRow
                  key={row.employee_id}
                  row={row}
                  tiers={tiers.map((t) => t.name)}
                  saving={save.isPending || end.isPending}
                  onSave={async (patch) => {
                    const res = await save.mutateAsync([
                      { employee_id: row.employee_id, ...patch },
                    ]);
                    if (res.rejected?.length) toast.error(res.rejected[0]);
                    else toast.success("Saved");
                  }}
                  onEnd={async () => {
                    await end.mutateAsync(row.employee_id);
                    toast.success(`${row.name} taken off the plan`);
                  }}
                />
              ))
            )}
          </div>

          {/* Add someone the rule allows. Anyone it does not is simply not in this list. */}
          <div className="rounded-lg border border-border bg-card p-3">
            <p className="mb-2 text-label font-bold uppercase tracking-wide text-muted-foreground">
              Add someone
            </p>
            {eligible.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                Everyone this plan covers is already on it.
              </p>
            ) : (
              <div className="flex flex-wrap items-end gap-2">
                <div className="min-w-48 flex-1 space-y-1.5">
                  <Label htmlFor="pe-emp">Employee</Label>
                  {/* `undefined`, not "" — the kit's selects reject an empty value and show the
                      placeholder instead (see ui/select.tsx). */}
                  <SimpleSelect
                    id="pe-emp"
                    value={adding === "" ? undefined : String(adding)}
                    placeholder="Choose someone"
                    onValueChange={(v) => setAdding(Number(v))}
                    options={eligible.map((e) => ({
                      value: String(e.employee_id),
                      label: e.name,
                    }))}
                  />
                </div>
                {tiers.length > 0 && (
                  <div className="min-w-40 space-y-1.5">
                    <Label htmlFor="pe-tier">Tier</Label>
                    <SimpleSelect
                      id="pe-tier"
                      value={tier || undefined}
                      placeholder="No tier"
                      onValueChange={setTier}
                      options={tiers.map((t) => ({
                        value: t.name,
                        label: `${t.name} — ${formatPeso(t.employee_share)}/mo`,
                      }))}
                    />
                  </div>
                )}
                <Button onClick={add} disabled={adding === "" || save.isPending}>
                  <UserPlus />
                  Enrol
                </Button>
              </div>
            )}
          </div>

          {/* Past members stay listed: their enrolment is what a past payslip's deduction line
              refers to, so it is ended by date rather than deleted. */}
          {enrolled.some((e) => e.ended_at !== null) && (
            <div>
              <p className="mb-1.5 text-label font-bold uppercase tracking-wide text-muted-foreground">
                No longer on the plan
              </p>
              <ul className="space-y-1 text-sm text-muted-foreground">
                {enrolled
                  .filter((e) => e.ended_at !== null)
                  .map((e) => (
                    <li key={e.employee_id}>
                      {e.name} — until {formatDate(e.ended_at!)}
                    </li>
                  ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </>
  );
}

function MemberRow({
  row,
  tiers,
  saving,
  onSave,
  onEnd,
}: {
  row: PlanEnrollment;
  tiers: string[];
  saving: boolean;
  onSave: (patch: { tier?: string | null; employee_share?: number | null }) => Promise<void>;
  onEnd: () => Promise<void>;
}) {
  const [share, setShare] = useState(row.employee_share ?? "");

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card px-3 py-2.5">
      <div>
        <p className="text-sm font-semibold text-foreground">{row.name}</p>
        <p className="text-caption text-muted-foreground">
          {row.tier ? `${row.tier} · ` : ""}
          {row.enrolled_at ? `since ${formatDate(row.enrolled_at)}` : "no start date"}
        </p>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        {tiers.length > 0 && (
          <SimpleSelect
            value={row.tier ?? undefined}
            placeholder="No tier"
            onValueChange={(v) => onSave({ tier: v || null })}
            options={tiers.map((t) => ({ value: t, label: t }))}
          />
        )}
        <Input
          type="number"
          min="0"
          step="0.01"
          className="h-9 w-28"
          placeholder="Share"
          value={share}
          onChange={(e) => setShare(e.target.value)}
          onBlur={() => {
            const next = share === "" ? null : Number(share);
            if (next !== (row.employee_share === null ? null : Number(row.employee_share))) {
              onSave({ employee_share: next });
            }
          }}
        />
        <Button variant="ghost" size="sm" disabled={saving} onClick={onEnd}>
          Take off
        </Button>
      </div>
    </div>
  );
}
