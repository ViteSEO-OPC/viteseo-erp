"use client";

import { useState } from "react";
import { AxiosError } from "axios";
import { Trash2, Plus } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { notify as toast } from "@/core/lib/notify";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { EligibilityPicker } from "@/modules/hr/components/eligibility-picker";
import { DeleteConfirmDialog } from "@/modules/hr/components/delete-confirm-dialog";
import { useEmployees } from "@/modules/hr/hooks/use-employees";
import {
  useCreateBenefitProgram,
  usePatchBenefitProgram,
  useDeleteBenefitProgram,
} from "@/modules/hr/hooks/use-benefit-programs";
import {
  BENEFIT_CATEGORIES,
  ELIGIBILITY_MODES,
  type BenefitProgram,
  type BenefitTier,
  type EligibilityMode,
} from "@/modules/hr/types/benefits";

/** Add or edit a company benefit — one form for both. */
export function BenefitPlanDialog({
  open,
  program,
  onOpenChange,
}: {
  open: boolean;
  /** null ⇒ creating a new benefit. */
  program: BenefitProgram | null;
  onOpenChange: (open: boolean) => void;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-xl">
        {open && (
          <PlanForm
            key={program?.id ?? "new"}
            program={program}
            onDone={() => onOpenChange(false)}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}

/** Cadence, with "not deducted" as a real choice rather than the absence of one. */
const CADENCES = [
  { value: "none", label: "Not deducted — the company pays" },
  { value: "per_cutoff", label: "Every cutoff" },
  { value: "monthly", label: "Monthly" },
];

const isDraftStatus = (s: string) => /soon|draft/i.test(s);

function PlanForm({ program, onDone }: { program: BenefitProgram | null; onDone: () => void }) {
  const isNew = program === null;
  const create = useCreateBenefitProgram();
  const patch = usePatchBenefitProgram();
  const remove = useDeleteBenefitProgram();
  const { data: employees } = useEmployees();
  const saving = create.isPending || patch.isPending || remove.isPending;

  const [confirming, setConfirming] = useState(false);
  const [name, setName] = useState(program?.name ?? "");
  const [category, setCategory] = useState(program?.category ?? "hmo");
  const [description, setDescription] = useState(program?.description ?? "");
  const [cadence, setCadence] = useState(program?.deduction_cadence ?? "none");
  const [tiers, setTiers] = useState<BenefitTier[]>(program?.tiers ?? []);
  const [mode, setMode] = useState<EligibilityMode>(
    (program?.eligibility as EligibilityMode) ?? "all",
  );
  const [statuses, setStatuses] = useState<string[]>(program?.eligibility_statuses ?? []);
  const [employeeIds, setEmployeeIds] = useState<number[]>(program?.employee_ids ?? []);

  const deducts = cadence !== "none";
  const ruleEmpty =
    (mode === "status" && statuses.length === 0) || (mode === "custom" && employeeIds.length === 0);
  const valid = name.trim() !== "" && !ruleEmpty;

  function setTier(i: number, key: keyof BenefitTier, value: string) {
    setTiers((prev) =>
      prev.map((t, idx) => (idx === i ? { ...t, [key]: key === "name" ? value : Number(value) } : t)),
    );
  }

  async function save(publish?: boolean) {
    const body = {
      name: name.trim(),
      kind: "plan",
      category,
      description: description.trim() || null,
      deduction_cadence: deducts ? cadence : null,
      // Tiers only mean something when there is a share to price. Cleared otherwise so a plan
      // switched to "not deducted" cannot keep quoting premiums nobody pays.
      tiers: deducts ? tiers.filter((t) => t.name.trim()) : [],
      eligibility: mode,
      eligibility_statuses: mode === "status" ? statuses : [],
      // `employee_ids` is the rule's list for `custom` — being ELIGIBLE is not the same as being
      // enrolled, which is still done deliberately from "Who is on it".
      employee_ids: mode === "custom" ? employeeIds : [],
      ...(publish === undefined ? {} : { status: publish ? "Active" : "Coming soon" }),
    };

    try {
      if (isNew) {
        await create.mutateAsync(body as never);
        toast.success(`${body.name} added${publish ? "" : " as a draft"}`);
      } else {
        await patch.mutateAsync({ id: program.id, patch: body });
        toast.success(publish ? "Published" : "Saved");
      }
      onDone();
    } catch (err) {
      const errors = err instanceof AxiosError ? err.response?.data?.errors : null;
      toast.error(errors ? String(Object.values(errors)[0]) : "Could not save the benefit.");
    }
  }

  const draft = program ? isDraftStatus(program.status) : true;

  return (
    <>
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2.5">
          {isNew ? "Add a company benefit" : `Edit ${program.name}`}
          {!isNew && draft && (
            <span className="rounded-full bg-warning-subtle px-2 py-0.5 text-xs font-semibold text-warning">
              Draft
            </span>
          )}
        </DialogTitle>
        <DialogDescription>
          Something employees are enrolled in — health cover, insurance, a retirement plan. It can
          take a share from their pay each period, or cost them nothing.
        </DialogDescription>
      </DialogHeader>

      <div className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="bp-name">Name</Label>
            <Input
              id="bp-name"
              value={name}
              placeholder="e.g. MediCare Plus HMO"
              onChange={(e) => setName(e.target.value)}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="bp-cat">Kind</Label>
            {/*
             * Drives the icon on the employee's own card, so it is a picker rather than free
             * text — a typo would silently fall back to the generic gift icon.
             */}
            <SimpleSelect
              id="bp-cat"
              value={category}
              onValueChange={setCategory}
              options={BENEFIT_CATEGORIES.filter((c) => c.value !== "allowance")}
            />
          </div>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="bp-desc">What it covers</Label>
          <Input
            id="bp-desc"
            value={description}
            placeholder="Shown to employees on their My Benefits page"
            onChange={(e) => setDescription(e.target.value)}
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="bp-cadence">Employee&apos;s share</Label>
          <SimpleSelect
            id="bp-cadence"
            value={cadence}
            onValueChange={setCadence}
            options={CADENCES}
          />
        </div>

        {deducts && (
          <div className="space-y-2">
            <Label>Coverage tiers</Label>
            <p className="text-caption text-muted-foreground">
              Optional. A tier fills in someone&apos;s share when they are enrolled, so the same
              figure is not typed twice.
            </p>
            {tiers.length > 0 && (
              <div className="grid grid-cols-[1fr_1fr_1fr_auto] items-center gap-2 text-label font-bold uppercase tracking-wide text-muted-foreground">
                <span>Tier</span>
                <span>Monthly premium</span>
                <span>Employee share</span>
                <span className="sr-only">Remove</span>
              </div>
            )}
            {tiers.map((t, i) => (
              <div key={i} className="grid grid-cols-[1fr_1fr_1fr_auto] items-center gap-2">
                <Input
                  value={t.name}
                  placeholder="Self"
                  onChange={(e) => setTier(i, "name", e.target.value)}
                />
                <Input
                  type="number"
                  min="0"
                  step="0.01"
                  value={t.monthly_premium}
                  onChange={(e) => setTier(i, "monthly_premium", e.target.value)}
                />
                <Input
                  type="number"
                  min="0"
                  step="0.01"
                  value={t.employee_share}
                  onChange={(e) => setTier(i, "employee_share", e.target.value)}
                />
                <Button
                  variant="ghost"
                  size="icon-sm"
                  onClick={() => setTiers((prev) => prev.filter((_, idx) => idx !== i))}
                >
                  <Trash2 />
                  <span className="sr-only">Remove tier</span>
                </Button>
              </div>
            ))}
            <Button
              variant="outline"
              size="sm"
              onClick={() =>
                setTiers((prev) => [...prev, { name: "", monthly_premium: 0, employee_share: 0 }])
              }
            >
              <Plus />
              Add a tier
            </Button>
          </div>
        )}

        {/*
         * Who MAY be enrolled. Being covered by the rule is not the same as being on the plan —
         * a tier and a share are per person, so enrolling stays a deliberate act.
         */}
        <EligibilityPicker
          mode={mode}
          statuses={statuses}
          employeeIds={employeeIds}
          employees={employees ?? []}
          // No `custom` here. On a plan the enrolment pivot IS the membership, so a named-people
          // rule would write the same rows "Who is on it" writes — enrolling them with no tier and
          // no share, which is the distinction B2 exists to keep.
          modes={ELIGIBILITY_MODES.filter((m) => m.value !== "custom")}
          onModeChange={setMode}
          onStatusesChange={setStatuses}
          onEmployeeIdsChange={setEmployeeIds}
        />

        <div className="rounded-lg border border-border bg-card px-3 py-2.5 text-xs text-foreground">
          This rule decides who <strong>may</strong> be put on the benefit. Nobody is enrolled by
          saving it — use <strong>“Who is on it”</strong> on the card to add people and set what
          each of them pays.
        </div>

        {!isNew && (
          <div className="flex flex-col gap-3 rounded-lg border border-danger/40 bg-danger-subtle px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="text-sm font-semibold text-danger">Delete this benefit</p>
              <p className="text-xs text-muted-foreground">
                Enrolments stop being deducted. Past payslips keep their record of it.
              </p>
            </div>
            <Button variant="outline" size="sm" onClick={() => setConfirming(true)}>
              Delete
            </Button>
          </div>
        )}
      </div>

      <DialogFooter>
        <DialogClose asChild>
          <Button variant="outline">Cancel</Button>
        </DialogClose>
        {draft ? (
          <>
            <Button variant="outline" disabled={!valid || saving} onClick={() => save(false)}>
              Save as draft
            </Button>
            <Button disabled={!valid || saving} onClick={() => save(true)}>
              {saving ? "Saving…" : "Publish"}
            </Button>
          </>
        ) : (
          <Button disabled={!valid || saving} onClick={() => save()}>
            {saving ? "Saving…" : "Save changes"}
          </Button>
        )}
      </DialogFooter>

      {!isNew && (
        <DeleteConfirmDialog
          open={confirming}
          onOpenChange={setConfirming}
          title="Delete benefit"
          question={`Delete “${program.name}”?`}
          description={
            "Anyone enrolled stops having their share deducted from the next payroll cutoff. " +
            "Payslips that already charged it are unchanged — they keep their own record."
          }
          confirmLabel="Delete"
          isPending={saving}
          onConfirm={async () => {
            try {
              await remove.mutateAsync(program.id);
              toast.success(`${program.name} deleted`);
              onDone();
            } catch {
              toast.error("Could not delete the benefit.");
            }
          }}
        />
      )}
    </>
  );
}
