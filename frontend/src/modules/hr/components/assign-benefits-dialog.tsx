"use client";

import { useMemo, useState } from "react";
import { Search, Plus, Wallet, HeartPulse } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { EmployeeAvatar } from "@/modules/hr/ui/employee-avatar";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { formatPeso } from "@/core/lib/format";
import { statusLabel, type Employee } from "@/modules/hr/types/employee";
import {
  useAssignBenefits,
  useCreateBenefitPlan,
} from "@/modules/hr/hooks/use-benefit-programs";
import type { BenefitProgram } from "@/modules/hr/types/benefits";
import { SimpleSelect } from "@/modules/hr/ui/select";

export function AssignBenefitsDialog({
  open,
  onOpenChange,
  employees,
  allowances,
  hmoPlans,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  employees: Employee[];
  allowances: BenefitProgram[];
  /** Company plans that have coverage tiers (assignable HMO / health plans). */
  hmoPlans: BenefitProgram[];
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-2xl">
        <AssignForm
          key={open ? "open" : "closed"}
          employees={employees}
          allowances={allowances}
          hmoPlans={hmoPlans}
          onDone={() => onOpenChange(false)}
        />
      </DialogContent>
    </Dialog>
  );
}

function AssignForm({
  employees,
  allowances,
  hmoPlans,
  onDone,
}: {
  employees: Employee[];
  allowances: BenefitProgram[];
  hmoPlans: BenefitProgram[];
  onDone: () => void;
}) {
  const assign = useAssignBenefits();
  const createPlan = useCreateBenefitPlan();

  const [search, setSearch] = useState("");
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [tab, setTab] = useState<"allowances" | "hmo">("allowances");

  // Allowances tab.
  const [pickedAllowances, setPickedAllowances] = useState<Set<number>>(new Set());
  const [showNew, setShowNew] = useState(false);
  const [newName, setNewName] = useState("");
  const [newAmount, setNewAmount] = useState("");

  // HMO tab.
  const [hmoId, setHmoId] = useState<number | null>(hmoPlans[0]?.id ?? null);
  const [tierByEmp, setTierByEmp] = useState<Record<number, string>>({});

  const hmo = hmoPlans.find((h) => h.id === hmoId) ?? null;
  const defaultTier = hmo?.tiers[0]?.name ?? "";

  // Eligibility: if any picked allowance is "regular only", non-regular staff can't be
  // selected on the Allowances tab. The HMO tab has no eligibility restriction.
  const requiresRegular =
    tab === "allowances" &&
    allowances.some((a) => pickedAllowances.has(a.id) && a.eligibility === "regular");
  const isEligible = (e: Employee) => !requiresRegular || e.employment_status === "regular";

  const filtered = useMemo(
    () =>
      employees.filter((e) => e.name.toLowerCase().includes(search.trim().toLowerCase())),
    [employees, search],
  );
  const eligibleCount = employees.filter(isEligible).length;
  const selectedEligible = [...selected].filter((id) => {
    const e = employees.find((x) => x.id === id);
    return e && isEligible(e);
  });

  function toggleEmployee(e: Employee) {
    if (!isEligible(e)) return;
    setSelected((prev) => {
      const next = new Set(prev);
      next.has(e.id) ? next.delete(e.id) : next.add(e.id);
      return next;
    });
  }

  function toggleAllowance(id: number) {
    setPickedAllowances((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }

  async function addNewAllowance() {
    if (!newName.trim() || newAmount === "") return;
    try {
      const created = await createPlan.mutateAsync({
        name: newName.trim(),
        category: "allowance",
        kind: "allowance",
        amount: Number(newAmount),
        status: "Active",
        color: "#3b82f6",
      });
      setPickedAllowances((prev) => new Set(prev).add(created.id));
      setNewName("");
      setNewAmount("");
      setShowNew(false);
      toast.success(`Added "${created.name}"`);
    } catch {
      toast.error("Could not add the allowance.");
    }
  }

  function setAllTiers(tier: string) {
    const next: Record<number, string> = {};
    selectedEligible.forEach((id) => (next[id] = tier));
    setTierByEmp((prev) => ({ ...prev, ...next }));
  }

  const canAssign =
    selectedEligible.length > 0 &&
    (tab === "allowances" ? pickedAllowances.size > 0 : !!hmo);

  async function submit() {
    if (!canAssign) return;
    const employee_ids = selectedEligible;
    try {
      if (tab === "allowances") {
        await assign.mutateAsync({ employee_ids, allowance_ids: [...pickedAllowances] });
      } else if (hmo) {
        const tiers: Record<number, string> = {};
        employee_ids.forEach((id) => (tiers[id] = tierByEmp[id] ?? defaultTier));
        await assign.mutateAsync({ employee_ids, hmo: { program_id: hmo.id, tiers } });
      }
      toast.success(`Benefits assigned to ${employee_ids.length} employee(s)`);
      onDone();
    } catch {
      toast.error("Could not assign the benefits.");
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>Add a Plan</DialogTitle>
        <DialogDescription>
          Allowances and HMO — government mandated is automatic.
        </DialogDescription>
      </DialogHeader>

      {/* Employees */}
      <div className="space-y-2">
        <div className="flex items-center justify-between">
          <Label>Employees</Label>
          <div className="relative w-56">
            <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              className="h-8 pl-8"
              placeholder="Search by name"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
        </div>
        <div className="max-h-48 space-y-0.5 overflow-y-auto rounded-lg border border-border p-1">
          {filtered.map((e) => {
            const eligible = isEligible(e);
            const checked = selected.has(e.id);
            return (
              <button
                key={e.id}
                type="button"
                disabled={!eligible}
                onClick={() => toggleEmployee(e)}
                className={cn(
                  "flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-left transition-colors",
                  eligible ? "hover:bg-muted" : "cursor-not-allowed opacity-50",
                )}
              >
                <span
                  className={cn(
                    "flex size-4 shrink-0 items-center justify-center rounded border",
                    checked ? "border-success bg-success text-white" : "border-input",
                  )}
                >
                  {checked && <span className="text-[10px] leading-none">✓</span>}
                </span>
                <EmployeeAvatar
                  name={e.name}
                  photoUrl={e.photo_url}
                  colorSeed={e.id}
                  size="sm"
                />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium text-foreground">{e.name}</p>
                  <p className="truncate text-caption text-muted-foreground">
                    {e.department?.name ?? "—"} · {e.employee_no}
                  </p>
                </div>
                <span className="shrink-0 text-caption text-muted-foreground">
                  {statusLabel(e.employment_status)}
                </span>
              </button>
            );
          })}
          {filtered.length === 0 && (
            <p className="px-2 py-4 text-center text-caption text-muted-foreground">
              No employees match.
            </p>
          )}
        </div>
        <p className="text-caption text-muted-foreground">
          {selectedEligible.length} selected of {eligibleCount} eligible
        </p>
      </div>

      {/* Tabs */}
      <div className="flex gap-2">
        <TabButton active={tab === "allowances"} onClick={() => setTab("allowances")} icon={Wallet}>
          Allowances
        </TabButton>
        <TabButton active={tab === "hmo"} onClick={() => setTab("hmo")} icon={HeartPulse}>
          HMO / health plan
        </TabButton>
      </div>

      {/* Allowances tab */}
      {tab === "allowances" && (
        <div className="space-y-2">
          <Label>Allowances to assign — applied to all selected</Label>
          <div className="flex flex-wrap gap-2">
            {allowances.map((a) => {
              const on = pickedAllowances.has(a.id);
              return (
                <button
                  key={a.id}
                  type="button"
                  onClick={() => toggleAllowance(a.id)}
                  className={cn(
                    "rounded-full border px-3 py-1 text-sm font-medium transition-colors",
                    on
                      ? "border-brand bg-brand text-brand-foreground"
                      : "border-input text-foreground hover:bg-muted",
                  )}
                >
                  {a.name} · {a.amount ? formatPeso(a.amount) : "—"}
                </button>
              );
            })}
            {!showNew ? (
              <button
                type="button"
                onClick={() => setShowNew(true)}
                className="rounded-full border border-dashed border-input px-3 py-1 text-sm text-muted-foreground hover:bg-muted"
              >
                <Plus className="mr-1 inline size-3.5" />
                Type another
              </button>
            ) : (
              <div className="flex items-center gap-2">
                <Input
                  className="h-8 w-40"
                  placeholder="Allowance name"
                  value={newName}
                  onChange={(e) => setNewName(e.target.value)}
                />
                <Input
                  className="h-8 w-24"
                  type="number"
                  min="0"
                  placeholder="Amount"
                  value={newAmount}
                  onChange={(e) => setNewAmount(e.target.value)}
                />
                <Button
                  size="sm"
                  onClick={addNewAllowance}
                  disabled={createPlan.isPending || !newName.trim() || newAmount === ""}
                >
                  Add
                </Button>
              </div>
            )}
          </div>
        </div>
      )}

      {/* HMO tab */}
      {tab === "hmo" && (
        <div className="space-y-2">
          {!hmo ? (
            <p className="rounded-lg border border-dashed border-border px-3 py-4 text-center text-sm text-muted-foreground">
              No health plan with tiers yet. Add a company benefit and set its coverage tiers first.
            </p>
          ) : (
            <>
              <div className="flex items-center justify-between gap-3">
                <div className="min-w-0">
                  {hmoPlans.length > 1 ? (
                    <SimpleSelect
                      className="w-56"
                      value={hmoId != null ? String(hmoId) : ""}
                      onValueChange={(v) => setHmoId(Number(v))}
                      options={hmoPlans.map((h) => ({ value: String(h.id), label: h.name }))}
                    />
                  ) : (
                    <p className="text-sm font-semibold text-foreground">{hmo.name}</p>
                  )}
                  <p className="text-caption text-muted-foreground">tier per employee</p>
                </div>
                <div className="flex items-center gap-2 text-caption text-muted-foreground">
                  Set all to
                  <SimpleSelect
                    className="w-32"
                    value=""
                    onValueChange={(v) => v && setAllTiers(v)}
                    placeholder="—"
                    options={hmo.tiers.map((t) => ({ value: t.name, label: t.name }))}
                  />
                </div>
              </div>
              <div className="max-h-40 space-y-1 overflow-y-auto">
                {selectedEligible.length === 0 ? (
                  <p className="text-caption text-muted-foreground">Select employees above first.</p>
                ) : (
                  selectedEligible.map((id) => {
                    const e = employees.find((x) => x.id === id)!;
                    const tierName = tierByEmp[id] ?? defaultTier;
                    const tier = hmo.tiers.find((t) => t.name === tierName);
                    const cutoff = tier?.employee_share ?? 0;
                    return (
                      <div key={id} className="flex items-center justify-between gap-3">
                        <div className="min-w-0">
                          <p className="truncate text-sm font-medium text-foreground">{e.name}</p>
                          <p className="text-caption text-muted-foreground">
                            Deduct {formatPeso(cutoff * 2)}/mo ({formatPeso(cutoff)}/cutoff)
                          </p>
                        </div>
                        <SimpleSelect
                          className="w-32"
                          value={tierName}
                          onValueChange={(v) =>
                            setTierByEmp((prev) => ({ ...prev, [id]: v }))
                          }
                          options={hmo.tiers.map((t) => ({ value: t.name, label: t.name }))}
                        />
                      </div>
                    );
                  })
                )}
              </div>
            </>
          )}
        </div>
      )}

      <DialogFooter>
        <DialogClose asChild>
          <Button variant="outline">Cancel</Button>
        </DialogClose>
        <Button onClick={submit} disabled={!canAssign || assign.isPending}>
          {assign.isPending ? "Assigning…" : "Assign benefits"}
        </Button>
      </DialogFooter>
    </>
  );
}

function TabButton({
  active,
  onClick,
  icon: Icon,
  children,
}: {
  active: boolean;
  onClick: () => void;
  icon: typeof Wallet;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        "flex flex-1 items-center justify-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition-colors",
        active
          ? "border-brand bg-brand-subtle text-dark-teal"
          : "border-input text-muted-foreground hover:bg-muted",
      )}
    >
      <Icon className="size-4" />
      {children}
    </button>
  );
}
