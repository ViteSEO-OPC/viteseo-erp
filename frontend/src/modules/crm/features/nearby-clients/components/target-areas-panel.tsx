"use client";

import { useState } from "react";
import { Trash2 } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { cn } from "@/modules/crm/lib/utils";
import {
  useServiceAreas,
  useCreateServiceArea,
  useDeleteServiceArea,
} from "@/modules/crm/features/nearby-clients/hooks/use-service-areas";
import type { ServiceAreaLevel } from "@/modules/crm/features/nearby-clients/types/nearby-clients-types";
import { SelectShell } from "@/modules/crm/components/shared/select-shell/select-shell";

const FIELD =
  "h-10 w-full rounded-lg border border-border bg-background px-3.5 text-sm text-foreground placeholder:text-muted-foreground focus:border-teal-400 focus:outline-none focus:ring-2 focus:ring-teal-400/30";
const SELECT = cn(FIELD, "appearance-none pr-8");

const LEVELS: { value: ServiceAreaLevel; label: string }[] = [
  { value: "city", label: "City" },
  { value: "province", label: "Province" },
  { value: "region", label: "Region" },
];

const LEVEL_LABEL: Record<ServiceAreaLevel, string> = {
  city: "City",
  province: "Province",
  region: "Region",
};

/**
 * Target Service Areas — the PO-managed registry that defines which administrative areas count as a
 * "target service location" (frozen Target Location +10). Admin/SA only. Adding/removing areas is
 * business configuration; the score itself is never computed here — the summary count comes from the
 * server-side `Lead::scopeInTargetLocation` determination.
 */
export function TargetAreasPanel({ className }: { className?: string }) {
  const { areas, summary, isLoading, isError } = useServiceAreas();
  const create = useCreateServiceArea();
  const remove = useDeleteServiceArea();
  const [name, setName] = useState("");
  const [level, setLevel] = useState<ServiceAreaLevel>("city");

  const handleAdd = () => {
    if (!name.trim()) return;
    create.mutate({ name: name.trim(), level }, { onSuccess: () => setName("") });
  };

  return (
    <div className={cn("flex flex-col gap-4 rounded-xl border border-border bg-card p-6 shadow-card", className)}>
      <div>
        <h3 className="text-sm font-bold text-card-foreground">Target Service Areas</h3>
        <p className="mt-1 text-xs text-muted-foreground">
          Administrative areas that count as a target location for the Lead Score. Managed by admins;
          supplies data to the KPI Engine — no score is computed here.
        </p>
      </div>

      {summary ? (
        <p className="rounded-lg bg-teal-50 px-3 py-2 text-xs font-medium text-teal-800">
          {summary.leadsInTargetLocation} of {summary.totalLeads} leads fall in a target service area.
        </p>
      ) : null}

      {/* Add form */}
      <div className="flex flex-col gap-2 sm:flex-row">
        <input
          className={FIELD}
          placeholder="Area name (e.g. Makati City)"
          value={name}
          onChange={(e) => setName(e.target.value)}
          onKeyDown={(e) => { if (e.key === "Enter") handleAdd(); }}
          aria-label="Target area name"
        />
        <SelectShell>
          <select
            className={cn(SELECT, "sm:w-40")}
            value={level}
            onChange={(e) => setLevel(e.target.value as ServiceAreaLevel)}
            aria-label="Area level"
          >
            {LEVELS.map((l) => (
              <option key={l.value} value={l.value}>{l.label}</option>
            ))}
          </select>
        </SelectShell>
        <Button
          variant="default"
          size="default"
          onClick={handleAdd}
          disabled={create.isPending || name.trim() === ""}
        >
          Add
        </Button>
      </div>

      {/* List */}
      {isLoading && <div className="h-24 w-full animate-pulse rounded-lg bg-muted" />}
      {isError && <ErrorState message="Failed to load target areas" />}
      {!isLoading && !isError && areas && areas.length > 0 && (
        <ul className="divide-y divide-border-subtle">
          {areas.map((area) => (
            <li key={area.id} className="flex items-center justify-between gap-3 py-2.5">
              <div className="flex min-w-0 items-center gap-2">
                <span className="truncate text-sm font-medium text-foreground">{area.name}</span>
                <span className="shrink-0 rounded-full bg-muted px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                  {LEVEL_LABEL[area.level]}
                </span>
              </div>
              <button
                type="button"
                onClick={() => remove.mutate(area.id)}
                disabled={remove.isPending}
                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-red-50 hover:text-red-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                aria-label={`Remove ${area.name}`}
              >
                <Trash2 className="h-4 w-4" />
              </button>
            </li>
          ))}
        </ul>
      )}
      {!isLoading && !isError && (!areas || areas.length === 0) && (
        <EmptyState message="No target areas yet — add one to define where leads earn the Target Location bonus." />
      )}
    </div>
  );
}
