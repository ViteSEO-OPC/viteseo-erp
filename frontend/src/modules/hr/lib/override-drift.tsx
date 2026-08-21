import { notify as toast } from "@/core/lib/notify";
import { ToastNoteList } from "@/modules/hr/components/toast-note-list";
import type { PayrollRun } from "@/modules/hr/types/payroll";

/**
 * WHAT A RECOMPUTE FOUND UNDERNEATH THE CORRECTIONS, said to the person who ran it.
 *
 * A correction is absolute and still wins, so none of these moved a figure — which is exactly why
 * they have to be said out loud. Nothing on the screen looks wrong: the payslip prints the figure
 * HR pinned, and the engine has quietly stopped agreeing with the figure it was pinned against.
 * Only the person who made the correction can decide whether it still belongs, and this used to
 * reach them nowhere but the audit log.
 *
 * A warning rather than a success: it is a thing to go and look at, not a thing that went well.
 */
export function notifyOverrideDrift(run: PayrollRun | undefined | null): void {
  const notes = run?.override_notes ?? [];

  if (notes.length === 0) {
    return;
  }

  toast.warning(
    `${notes.length} correction${notes.length === 1 ? "" : "s"} may no longer be right`,
    <ToastNoteList notes={notes} />,
  );
}
