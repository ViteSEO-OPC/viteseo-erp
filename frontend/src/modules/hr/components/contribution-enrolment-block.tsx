"use client";

import { useQueryClient } from "@tanstack/react-query";

import { notify as toast } from "@/core/lib/notify";
import {
  useEmployeeBenefits,
  useMyContributions,
  useUpdateEmployeeBenefits,
} from "@/modules/hr/hooks/use-benefits";
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
export function ContributionEnrolmentBlock({
  employeeId,
  canEdit = true,
}: {
  employeeId: number;
  /**
   * False on the employee's own Compensation tab. Two things change, and both are needed:
   * the toggles become plain read-only rows, AND the data comes from the caller's own endpoint —
   * the HR reader is hr_officer-only, so an employee got a 403, no rows, and `enrolledOf()`
   * fell back to its "enrolled" default, drawing three ticked and clickable checkboxes for a
   * change the server was always going to refuse.
   */
  canEdit?: boolean;
}) {
  const hrQuery = useEmployeeBenefits(canEdit ? employeeId : null);
  const myQuery = useMyContributions(!canEdit);
  const rows = canEdit ? hrQuery.data : myQuery.data;
  const isLoading = canEdit ? hrQuery.isLoading : myQuery.isLoading;
  const update = useUpdateEmployeeBenefits(employeeId);
  const qc = useQueryClient();

  const enrolledOf = (type: string) =>
    (rows ?? []).find((r) => r.contribution_type === type)?.enrolled ?? true;

  async function toggle(type: string, next: boolean) {
    try {
      await update.mutateAsync([{ contribution_type: type, enrolled: next }]);
      // The record carries the enrolment state, so the tab above has to refetch or the two
      // disagree on screen. (The statutory-override preview this used to refresh is gone: there
      // are no per-employee amounts any more.)
      qc.invalidateQueries({ queryKey: ["hr", "employees", employeeId] });
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
        {canEdit ? (
          <>
            Everyone is enrolled in all three by default. Switching one off removes it from this
            employee&apos;s payslip entirely — <strong>both</strong> their share and the
            employer&apos;s — from the next payroll run. Saved as soon as you change it.
          </>
        ) : (
          <>
            The government contributions deducted from your pay. Each one is itemised on your
            payslip. Contact HR if something here looks wrong.
          </>
        )}
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
                  className="size-4 rounded border-input accent-brand disabled:opacity-100"
                  checked={enrolled}
                  disabled={!canEdit || update.isPending}
                  readOnly={!canEdit}
                  onChange={(e) => canEdit && toggle(type, e.target.checked)}
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
