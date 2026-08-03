"use client";

import { useQueryClient } from "@tanstack/react-query";

import { notify as toast } from "@/core/lib/notify";
import { useEmployeeBenefits, useUpdateEmployeeBenefits } from "@/modules/hr/hooks/use-benefits";
import { CONTRIBUTION_LABELS } from "@/modules/hr/types/benefits";

/** The three the engine knows about, in display order. */
const TYPES = ["sss", "philhealth", "pagibig"] as const;

/**
 * Which government contributions this employee pays into — Slice B9 item 3.
 *
 * WHAT WAS MISSING. Everybody was enrolled in everything, always. The endpoints to change that
 * existed and worked, the hooks were written, and nothing in the product called either — so an
 * exempt employee (a foreign national covered at home, a rehired retiree) could not be excluded,
 * and the only way anyone found to express "this person is different" was to type a peso amount
 * into the override boxes above. That override replaces the employee half alone and produces a
 * remittance pair that cannot be filed, which is why B7 item 4 pointed at this screen: un-enrolling
 * is the clean way to say it, because it takes BOTH halves off the payslip together.
 *
 * SAVES ON THE SPOT, deliberately outside the section's Edit/Save cycle. It writes to a different
 * endpoint from the profile fields, and a toggle that silently waits for a Save button two
 * headings away is how people end up believing they changed something they did not.
 */
export function ContributionEnrolmentBlock({ employeeId }: { employeeId: number }) {
  const { data: rows, isLoading } = useEmployeeBenefits(employeeId);
  const update = useUpdateEmployeeBenefits(employeeId);
  const qc = useQueryClient();

  const enrolledOf = (type: string) =>
    (rows ?? []).find((r) => r.contribution_type === type)?.enrolled ?? true;

  async function toggle(type: string, next: boolean) {
    try {
      await update.mutateAsync([{ contribution_type: type, enrolled: next }]);
      // The override fields above caption themselves with "Not enrolled — nothing is deducted",
      // so that preview has to be refetched or the two blocks contradict each other on screen.
      qc.invalidateQueries({ queryKey: ["hr", "employees", employeeId, "statutory-preview"] });
      toast.success(
        next
          ? `${CONTRIBUTION_LABELS[type] ?? type} enrolled`
          : `${CONTRIBUTION_LABELS[type] ?? type} stopped — it will not be deducted`,
      );
    } catch {
      toast.error("Could not change the enrolment.");
    }
  }

  return (
    <div className="border-t border-border pt-6">
      <p className="mb-1 text-label font-semibold uppercase tracking-wide text-brand">
        Enrolled contributions
      </p>
      <p className="mb-4 text-caption text-muted-foreground">
        Everyone is enrolled in all three by default. Switching one off removes it from this
        employee&apos;s payslip entirely — <strong>both</strong> their share and the employer&apos;s
        — from the next payroll run. Saved as soon as you change it.
      </p>

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : (
        <div className="grid gap-3 sm:grid-cols-3">
          {TYPES.map((type) => {
            const enrolled = enrolledOf(type);

            return (
              <label
                key={type}
                className="flex items-center gap-2.5 rounded-lg border border-border bg-card px-3 py-2.5"
              >
                <input
                  type="checkbox"
                  className="size-4 rounded border-input accent-brand"
                  checked={enrolled}
                  disabled={update.isPending}
                  onChange={(e) => toggle(type, e.target.checked)}
                />
                <span className="text-sm font-medium text-foreground">
                  {CONTRIBUTION_LABELS[type] ?? type}
                </span>
                {!enrolled && (
                  <span className="ml-auto rounded-full bg-muted px-2 py-0.5 text-xs font-semibold text-muted-foreground">
                    Not deducted
                  </span>
                )}
              </label>
            );
          })}
        </div>
      )}
    </div>
  );
}
