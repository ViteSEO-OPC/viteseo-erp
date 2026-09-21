"use client";

import { useState } from "react";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { formatPeso } from "@/core/lib/format";
import { DeleteConfirmDialog } from "@/modules/hr/components/delete-confirm-dialog";
import { useDeleteInternAllowanceVersion } from "@/modules/hr/hooks/use-intern-allowance";
import {
  internAllowanceUnit,
  internDefaultAmount,
} from "@/modules/hr/lib/intern-allowance-options";
import type {
  InternAllowanceOverview,
  InternAllowanceVersion,
} from "@/modules/hr/types/intern-allowance";

const STATUS_STYLE: Record<InternAllowanceVersion["status"], string> = {
  current: "bg-success-subtle text-success",
  scheduled: "bg-warning-subtle text-warning",
  past: "bg-muted text-muted-foreground",
};

const STATUS_LABEL: Record<InternAllowanceVersion["status"], string> = {
  current: "In force",
  scheduled: "Scheduled",
  past: "Past",
};

/** Turn a 422 into the field message the server actually sent, rather than a generic failure. */
function serverMessage(err: unknown, fallback: string): string {
  if (err instanceof AxiosError && err.response?.status === 422) {
    const errors = err.response.data?.errors ?? {};
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && typeof first[0] === "string") return first[0];
    return err.response.data?.message ?? fallback;
  }
  return fallback;
}

/** Every version of the rules, newest first. A scheduled one can be withdrawn before it starts. */
export function InternAllowanceVersions({ overview }: { overview: InternAllowanceOverview }) {
  const deleteMut = useDeleteInternAllowanceVersion();
  const [withdrawing, setWithdrawing] = useState<InternAllowanceVersion | null>(null);

  return (
    <section className="space-y-5 rounded-card border border-border bg-card p-6">
      <div className="space-y-1">
        <h2 className="text-h3 font-bold text-foreground">Version history</h2>
        <p className="text-sm text-muted-foreground">
          Payroll prices each cycle at the version in force on its last day, so a change never
          reprices a cycle that already ended.
        </p>
      </div>

      <div className="overflow-x-auto">
        <table className="w-full min-w-[560px] text-sm">
          <thead className="text-left text-label uppercase tracking-wide text-muted-foreground">
            <tr className="border-b border-border">
              <th className="py-2 pr-4 font-semibold">Effective from</th>
              <th className="py-2 pr-4 font-semibold">Status</th>
              <th className="py-2 pr-4 font-semibold">Allowance</th>
              <th className="py-2 pr-4 font-semibold">Saved by</th>
              <th className="py-2" />
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {overview.versions.map((version) => (
              <tr key={version.effective_from}>
                <td className="py-2.5 pr-4 font-mono">
                  {version.effective_from === "2000-01-01" ? "Opening rules" : version.effective_from}
                </td>
                <td className="py-2.5 pr-4">
                  <span className={cn("rounded-full px-2 py-0.5 text-xs font-medium", STATUS_STYLE[version.status])}>
                    {STATUS_LABEL[version.status]}
                  </span>
                </td>
                <td className="py-2.5 pr-4">
                  {formatPeso(internDefaultAmount(version.settings))}{" "}
                  {internAllowanceUnit(version.settings.method)}
                </td>
                <td className="py-2.5 pr-4 text-muted-foreground">{version.created_by ?? "—"}</td>
                <td className="py-2.5 text-right">
                  {version.status === "scheduled" && (
                    <Button type="button" variant="outline" size="sm" onClick={() => setWithdrawing(version)}>
                      Withdraw
                    </Button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <DeleteConfirmDialog
        open={withdrawing !== null}
        onOpenChange={(o) => !o && setWithdrawing(null)}
        title="Withdraw scheduled rules"
        question={`Withdraw the rules scheduled for ${withdrawing?.effective_from ?? ""}?`}
        description="The rules in force before that date simply carry on. This is recorded in the audit log."
        confirmLabel="Withdraw"
        isPending={deleteMut.isPending}
        onConfirm={async () => {
          if (!withdrawing) return;
          try {
            await deleteMut.mutateAsync(withdrawing.effective_from);
          } catch (err) {
            /*
             * DeleteConfirmDialog swallows the throw and holds itself open EXPRESSLY so the caller
             * can say what went wrong. The server does refuse this — the date has since arrived, or
             * another session withdrew it already — and unsaid, that refusal reads as a dead button.
             * Rethrown so the dialog stays up for a retry.
             */
            toast.error(serverMessage(err, "Could not withdraw these rules."));
            throw err;
          }
          toast.success("Scheduled rules withdrawn");
          setWithdrawing(null);
        }}
      />
    </section>
  );
}
