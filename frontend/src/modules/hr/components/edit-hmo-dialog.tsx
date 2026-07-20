"use client";

import { useState } from "react";
import { Trash2, Plus } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

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
import { usePatchBenefitProgram } from "@/modules/hr/hooks/use-benefit-programs";
import { HMO_CADENCES, type BenefitProgram, type BenefitTier } from "@/modules/hr/types/benefits";

export function EditHmoDialog({
  program,
  onOpenChange,
}: {
  program: BenefitProgram | null;
  onOpenChange: (open: boolean) => void;
}) {
  return (
    <Dialog open={!!program} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-xl">
        {program && <HmoForm key={program.id} program={program} onDone={() => onOpenChange(false)} />}
      </DialogContent>
    </Dialog>
  );
}

const isDraftStatus = (s: string) => /soon|draft/i.test(s);

function HmoForm({ program, onDone }: { program: BenefitProgram; onDone: () => void }) {
  const patch = usePatchBenefitProgram();
  const [name, setName] = useState(program.name);
  const [cadence, setCadence] = useState(program.deduction_cadence ?? "per_cutoff");
  const [tiers, setTiers] = useState<BenefitTier[]>(
    program.tiers.length ? program.tiers : [{ name: "", monthly_premium: 0, employee_share: 0 }],
  );

  function setTier(i: number, key: keyof BenefitTier, value: string) {
    setTiers((prev) =>
      prev.map((t, idx) =>
        idx === i ? { ...t, [key]: key === "name" ? value : Number(value) } : t,
      ),
    );
  }
  function addTier() {
    setTiers((prev) => [...prev, { name: "", monthly_premium: 0, employee_share: 0 }]);
  }
  function removeTier(i: number) {
    setTiers((prev) => prev.filter((_, idx) => idx !== i));
  }

  async function save(publish: boolean) {
    const cleaned = tiers.filter((t) => t.name.trim());
    try {
      await patch.mutateAsync({
        id: program.id,
        patch: {
          name,
          deduction_cadence: cadence,
          tiers: cleaned,
          ...(publish ? { status: "Active" } : {}),
        },
      });
      toast.success(publish ? "Plan published" : "Draft saved");
      onDone();
    } catch {
      toast.error("Could not save the plan.");
    }
  }

  const draft = isDraftStatus(program.status);

  return (
    <>
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2.5">
          Edit {program.name}
          {draft && (
            <span className="rounded-full bg-warning-subtle px-2 py-0.5 text-xs font-semibold text-warning">
              Draft
            </span>
          )}
        </DialogTitle>
        <DialogDescription>Medical · company benefit</DialogDescription>
      </DialogHeader>

      <div className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="h-name">Provider and plan name</Label>
            <Input id="h-name" value={name} onChange={(e) => setName(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="h-cadence">Deducted</Label>
            <select
              id="h-cadence"
              className="h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring"
              value={cadence}
              onChange={(e) => setCadence(e.target.value)}
            >
              {HMO_CADENCES.map((c) => (
                <option key={c.value} value={c.value}>
                  {c.label}
                </option>
              ))}
            </select>
          </div>
        </div>

        <div className="space-y-2">
          <Label>Coverage tiers</Label>
          <div className="grid grid-cols-[1fr_1fr_1fr_auto] items-center gap-2 text-label font-bold uppercase tracking-wide text-muted-foreground">
            <span>Tier</span>
            <span>Monthly premium</span>
            <span>Employee share</span>
            <span className="sr-only">Remove</span>
          </div>
          {tiers.map((t, i) => (
            <div key={i} className="grid grid-cols-[1fr_1fr_1fr_auto] items-center gap-2">
              <Input value={t.name} placeholder="Self" onChange={(e) => setTier(i, "name", e.target.value)} />
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
                onClick={() => removeTier(i)}
                disabled={tiers.length === 1}
              >
                <Trash2 />
                <span className="sr-only">Remove tier</span>
              </Button>
            </div>
          ))}
          <Button variant="link" size="sm" className="px-0" onClick={addTier}>
            <Plus /> Add tier
          </Button>
        </div>

        {draft && (
          <div className="rounded-lg border border-brand/40 bg-brand-subtle px-3 py-2.5 text-xs text-foreground">
            Plan is in draft — no deductions run yet. Publishing makes tiers available in Assign
            benefits.
          </div>
        )}
      </div>

      <DialogFooter>
        <DialogClose asChild>
          <Button variant="outline">Cancel</Button>
        </DialogClose>
        <Button variant="outline" onClick={() => save(false)} disabled={patch.isPending}>
          Save draft
        </Button>
        <Button onClick={() => save(true)} disabled={patch.isPending}>
          {patch.isPending ? "Saving…" : "Publish plan"}
        </Button>
      </DialogFooter>
    </>
  );
}
