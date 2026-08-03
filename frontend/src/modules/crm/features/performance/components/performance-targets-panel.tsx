"use client";

import { useState } from "react";
import { Trash2 } from "lucide-react";
import { Card } from "@/modules/crm/components/ui/card";
import { Button } from "@/modules/crm/components/ui/button";
import { Badge } from "@/modules/crm/components/ui/badge";
import { Skeleton } from "@/modules/crm/components/ui/skeleton";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from "@/modules/crm/components/ui/select";
import { FormField, FormFieldGrid } from "@/modules/crm/components/forms/form-field/form-field";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { FIELD } from "@/modules/crm/features/settings/components/form-styles";
import { useUsers } from "@/modules/crm/features/settings/hooks/use-users-data";
import {
  usePerformanceTargets,
  useSaveTarget,
  useUpdateTarget,
  useDeleteTarget,
  type PerformanceTarget,
  type TargetMetric,
  type TargetPeriod,
} from "@/modules/crm/features/performance/hooks/use-performance-targets";

/**
 * Goal Management — the CRUD surface for the targets that power Personal Target (monthly) and the
 * Quarter Targets tiles (quarterly).
 *
 * Targets are PER-BD by product decision: there is no team-wide option here, because a CRM-wide
 * target is a different business object that has not been defined. Metrics are a closed set matching
 * the backend enum, so an unmeasurable goal cannot be entered.
 */

const METRICS: { value: TargetMetric; label: string; period: TargetPeriod }[] = [
  { value: "converted_leads", label: "Converted Leads (Personal Target)", period: "month" },
  { value: "leads_contacted", label: "Leads Contacted", period: "quarter" },
  { value: "consultations", label: "Consultations", period: "quarter" },
  { value: "confirmed_clients", label: "Confirmed Clients", period: "quarter" },
];

/** The canonical anchor the backend stores: the 1st of the current month or quarter. */
function anchorFor(period: TargetPeriod): string {
  const now = new Date();
  const month = period === "month" ? now.getMonth() : Math.floor(now.getMonth() / 3) * 3;
  return new Date(Date.UTC(now.getFullYear(), month, 1)).toISOString().slice(0, 10);
}

export function PerformanceTargetsPanel() {
  const { targets, isLoading, isError, retry } = usePerformanceTargets();
  const { users } = useUsers();
  const save = useSaveTarget();
  const update = useUpdateTarget();
  const remove = useDeleteTarget();

  const bds = users.filter((u) => u.role === "crm_bd" && u.status === "active");

  const [userId, setUserId] = useState("");
  const [metric, setMetric] = useState<TargetMetric>("converted_leads");
  const [value, setValue] = useState("10");
  const [deleteTarget, setDeleteTarget] = useState<PerformanceTarget | null>(null);

  // Period follows the metric — Personal Target is monthly, the Quarter tiles are quarterly. Pairing
  // them in the metric list makes an invalid combination unselectable rather than server-rejected.
  const period = METRICS.find((m) => m.value === metric)?.period ?? "month";

  const canSubmit = userId !== "" && Number(value) >= 0 && !save.isPending;

  return (
    <div className="flex flex-col gap-6">
      <Card className="border border-border bg-card p-6 shadow-card">
        <h3 className="text-base font-bold text-card-foreground">Set a target</h3>
        <p className="mb-5 text-sm text-muted-foreground">
          Targets are per Business Development Manager. Saving a target for a period that already has
          one replaces its value.
        </p>

        <FormFieldGrid>
          <FormField label="Business Developer">
            <Select value={userId} onValueChange={(v) => setUserId(v ?? "")}>
              <SelectTrigger className="w-full" aria-label="Business Developer">
                {/* The label is passed explicitly — the trigger otherwise renders the raw value (an id). */}
                <SelectValue placeholder="Select a BD">
                  {bds.find((u) => u.id === userId)?.name}
                </SelectValue>
              </SelectTrigger>
              <SelectContent>
                {bds.map((u) => (
                  <SelectItem key={u.id} value={u.id}>
                    {u.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </FormField>

          <FormField label="Metric">
            <Select value={metric} onValueChange={(v) => setMetric((v as TargetMetric) ?? metric)}>
              <SelectTrigger className="w-full" aria-label="Metric">
                <SelectValue>{METRICS.find((m) => m.value === metric)?.label}</SelectValue>
              </SelectTrigger>
              <SelectContent>
                {METRICS.map((m) => (
                  <SelectItem key={m.value} value={m.value}>
                    {m.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </FormField>
        </FormFieldGrid>

        <div className="mt-5 flex flex-wrap items-end gap-4">
          <FormField label="Target value">
            <input
              type="number"
              min={0}
              className={FIELD}
              value={value}
              onChange={(e) => setValue(e.target.value)}
            />
          </FormField>
          <div className="pb-1 text-sm text-muted-foreground">
            Period: <span className="font-semibold text-card-foreground">{period === "month" ? "This month" : "This quarter"}</span>
          </div>
          <Button
            variant="default"
            size="sm"
            className="ml-auto"
            disabled={!canSubmit}
            onClick={() =>
              save.mutate({
                user_id: userId,
                period_type: period,
                period_start: anchorFor(period),
                metric,
                target_value: Number(value),
              })
            }
          >
            {save.isPending ? "Saving…" : "Save target"}
          </Button>
        </div>
      </Card>

      <Card className="border border-border bg-card p-6 shadow-card">
        <h3 className="mb-4 text-base font-bold text-card-foreground">Configured targets</h3>

        {isError ? (
          <ErrorState message="Could not load performance targets." onRetry={() => void retry()} />
        ) : isLoading ? (
          <Skeleton className="h-24 w-full" />
        ) : targets.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            No targets configured. Personal Target and Quarter Targets stay in their pending state
            until one is set.
          </p>
        ) : (
          <div className="flex flex-col divide-y divide-border-subtle">
            {targets.map((t) => (
              <div key={t.id} className="flex flex-wrap items-center gap-3 py-3">
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-semibold text-card-foreground">
                    {t.userName ?? "—"} · {t.metricLabel}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {t.periodType === "month" ? "Monthly" : "Quarterly"} · from {t.periodStart}
                  </p>
                </div>

                <input
                  type="number"
                  min={0}
                  defaultValue={t.targetValue}
                  aria-label={`Target value for ${t.userName ?? "BD"}`}
                  className={`${FIELD} w-24`}
                  onBlur={(e) => {
                    const next = Number(e.target.value);
                    if (next !== t.targetValue) update.mutate({ id: t.id, target_value: next });
                  }}
                />

                <Badge variant={t.isActive ? "default" : "outline"}>
                  {t.isActive ? "Active" : "Retired"}
                </Badge>

                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => update.mutate({ id: t.id, is_active: !t.isActive })}
                >
                  {t.isActive ? "Retire" : "Reactivate"}
                </Button>

                <Button
                  variant="outline"
                  size="sm"
                  aria-label="Delete target"
                  onClick={() => setDeleteTarget(t)}
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              </div>
            ))}
          </div>
        )}
      </Card>

      <ConfirmDialog
        open={deleteTarget !== null}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
        title="Delete this target?"
        description={`The ${deleteTarget?.metricLabel} target for ${deleteTarget?.userName ?? "this BD"} will be removed. The surfaces it feeds return to their pending state.`}
        confirmLabel="Delete"
        destructive
        loading={remove.isPending}
        onConfirm={() =>
          deleteTarget &&
          remove.mutate(deleteTarget.id, { onSettled: () => setDeleteTarget(null) })
        }
      />
    </div>
  );
}
