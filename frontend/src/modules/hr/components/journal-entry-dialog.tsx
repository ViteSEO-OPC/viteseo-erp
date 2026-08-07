"use client";

import { useState } from "react";
import Link from "next/link";
import { AlertTriangle, BookOpen, Undo2 } from "lucide-react";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import {
  useRunJournal,
  useBuildRunJournal,
  usePostJournalEntry,
  useReverseJournalEntry,
} from "@/modules/hr/hooks/use-accounting";
import type { JournalEntry } from "@/modules/hr/types/accounting";

const peso = new Intl.NumberFormat("en-PH", {
  style: "currency",
  currency: "PHP",
  minimumFractionDigits: 2,
});

/** Show the message the server actually sent — "unmapped roles" and "already posted" are both actionable. */
function serverMessage(err: unknown, fallback: string): string {
  if (err instanceof AxiosError && err.response?.status === 422) {
    const errors = err.response.data?.errors ?? {};
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && typeof first[0] === "string") return first[0];
    return err.response.data?.message ?? fallback;
  }
  return fallback;
}

/**
 * A payroll run's general-ledger entry — generate, post, reverse (FIN-043 / MF-05).
 *
 * THE THREE STATES ARE THE WHOLE UI. A `draft` is computed and not on the books, so it can be rebuilt
 * freely. A `posted` entry is on the books and immutable — correcting it means reversing it, which writes
 * a mirror rather than deleting anything, because a posted journal is a statement about what the books
 * said on a date. A `reversed` entry stays visible alongside its mirror; that pair is the audit trail of
 * the correction, and it is also what frees the run to post again.
 *
 * IT SHOWS THE DEBIT AND CREDIT TOTALS SIDE BY SIDE, and says so when they disagree. An unbalanced entry
 * cannot post — the server refuses — and the reason is that it means the payslips do not add up. Surfacing
 * it here rather than as a bare error is the difference between "accounting is broken" and "this run has
 * a payroll problem".
 */
export function JournalEntryDialog({
  runId,
  runLabel,
  open,
  onOpenChange,
}: {
  runId: number | null;
  runLabel: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { data, isLoading } = useRunJournal(open ? runId : null);
  const buildMut = useBuildRunJournal();
  const postMut = usePostJournalEntry();
  const reverseMut = useReverseJournalEntry();

  const [postingDate, setPostingDate] = useState<string | null>(null);
  const [reversing, setReversing] = useState(false);
  const [reason, setReason] = useState("");

  const entry: JournalEntry | null = data?.entry ?? null;
  const date = postingDate ?? entry?.posting_date ?? data?.default_posting_date ?? "";
  const pending = buildMut.isPending || postMut.isPending || reverseMut.isPending;

  async function onBuild() {
    if (runId === null) return;
    try {
      await buildMut.mutateAsync({ runId, postingDate: date || undefined });
      toast.success("Draft entry generated", "Review the lines, then post it to the books.");
    } catch (err) {
      toast.error(serverMessage(err, "Could not generate the entry."));
    }
  }

  async function onPost() {
    if (!entry) return;
    try {
      await postMut.mutateAsync(entry.id);
      toast.success("Posted to the ledger");
    } catch (err) {
      toast.error(serverMessage(err, "Could not post the entry."));
    }
  }

  async function onReverse() {
    if (!entry) return;
    try {
      await reverseMut.mutateAsync({ id: entry.id, reason: reason.trim() });
      toast.success("Entry reversed", "A mirror entry was posted; the original stays on the books.");
      setReversing(false);
      setReason("");
    } catch (err) {
      toast.error(serverMessage(err, "Could not reverse the entry."));
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-[720px]">
        <DialogHeader>
          <DialogTitle>General ledger entry</DialogTitle>
          <DialogDescription>
            The double-entry for {runLabel}. A draft is not on the books yet; posting is what puts it
            there.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          {isLoading ? (
            <p className="text-sm text-muted-foreground">Checking the ledger…</p>
          ) : (
            <>
              <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="flex items-center gap-2">
                  <Label htmlFor="je-date" className="shrink-0">
                    Posting date
                  </Label>
                  <DatePicker
                    id="je-date"
                    value={date}
                    onChange={(v) => setPostingDate(v)}
                    // A posted entry's date is history; only a draft's can move.
                    disabled={!!entry && entry.status !== "draft"}
                    className="w-40"
                  />
                </div>
                {entry && (
                  <span
                    className={
                      entry.status === "posted"
                        ? "rounded-full bg-success-subtle px-2.5 py-0.5 text-xs font-semibold text-success"
                        : entry.status === "reversed"
                          ? "rounded-full bg-danger-subtle px-2.5 py-0.5 text-xs font-semibold text-danger"
                          : "rounded-full bg-warning-subtle px-2.5 py-0.5 text-xs font-semibold text-warning"
                    }
                  >
                    Entry #{entry.id} · {entry.status}
                  </span>
                )}
              </div>

              <p className="text-caption text-muted-foreground">
                The date it hits the books — not the run date and not the pay date. Defaults to the
                cycle end, the period the cost belongs to.
              </p>

              {!entry ? (
                <div className="rounded-lg border border-border p-4">
                  <p className="text-sm text-muted-foreground">
                    Nothing generated for this run yet. Generating builds a draft from the payslips; it
                    does not touch the books.
                  </p>
                  <p className="mt-2 text-caption text-muted-foreground">
                    It needs every payroll figure mapped to one of your accounts first —{" "}
                    <Link
                      href="/teamora/finance/accounting"
                      className="font-medium text-brand underline"
                    >
                      set up the chart of accounts
                    </Link>
                    .
                  </p>
                </div>
              ) : (
                <>
                  <div className="overflow-x-auto rounded-lg border border-border">
                    <table className="w-full min-w-[560px] text-sm">
                      <thead>
                        <tr className="border-b border-border bg-muted/60 text-label font-bold uppercase tracking-wide text-muted-foreground">
                          <th className="px-3 py-2 text-left">Account</th>
                          <th className="px-3 py-2 text-right">Debit</th>
                          <th className="px-3 py-2 text-right">Credit</th>
                        </tr>
                      </thead>
                      <tbody>
                        {entry.lines.map((line, i) => (
                          <tr key={i} className="border-b border-border last:border-0">
                            <td className="px-3 py-2">
                              <span className="font-medium text-foreground">
                                {line.account_code} · {line.account_name}
                              </span>
                              {line.memo && (
                                <span className="block text-xs text-muted-foreground">
                                  {line.memo}
                                </span>
                              )}
                            </td>
                            <td className="px-3 py-2 text-right text-foreground">
                              {line.debit > 0 ? peso.format(line.debit) : "—"}
                            </td>
                            <td className="px-3 py-2 text-right text-foreground">
                              {line.credit > 0 ? peso.format(line.credit) : "—"}
                            </td>
                          </tr>
                        ))}
                        <tr className="bg-muted/40 font-semibold">
                          <td className="px-3 py-2 text-foreground">Total</td>
                          <td className="px-3 py-2 text-right text-foreground">
                            {peso.format(entry.total_debit)}
                          </td>
                          <td className="px-3 py-2 text-right text-foreground">
                            {peso.format(entry.total_credit)}
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>

                  {!entry.is_balanced && (
                    <div className="flex items-start gap-2 rounded-lg border border-danger bg-danger-subtle p-3">
                      <AlertTriangle className="mt-0.5 size-4 shrink-0 text-danger" aria-hidden />
                      <p className="text-xs text-foreground">
                        <strong>The debits and credits disagree.</strong> This is a payroll problem, not
                        an accounting one — the payslips for this run do not add up, and it cannot be
                        posted until they do.
                      </p>
                    </div>
                  )}

                  {entry.status === "posted" && (
                    <p className="text-caption text-muted-foreground">
                      Posted{entry.posted_by ? ` by ${entry.posted_by}` : ""} on{" "}
                      {entry.posting_date}. It cannot be edited — a correction is a reversal, which
                      posts a mirror entry and leaves this one visible.
                    </p>
                  )}

                  {entry.status === "reversed" && (
                    <p className="text-caption text-muted-foreground">
                      This entry has been reversed. Both halves stay on the books, and the run is free
                      to post again.
                    </p>
                  )}

                  {reversing && (
                    <div className="space-y-1.5">
                      <Label htmlFor="je-reason">Why is this being reversed?</Label>
                      <textarea
                        id="je-reason"
                        rows={2}
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                        placeholder="Recorded on the mirror entry and in the audit trail"
                      />
                    </div>
                  )}
                </>
              )}
            </>
          )}
        </div>

        <DialogFooter>
          {entry?.status === "posted" &&
            (reversing ? (
              <>
                <Button variant="ghost" onClick={() => setReversing(false)} disabled={pending}>
                  Cancel
                </Button>
                <Button
                  variant="destructive"
                  onClick={() => onReverse()}
                  disabled={pending || reason.trim().length === 0}
                >
                  <Undo2 />
                  {reverseMut.isPending ? "Reversing…" : "Reverse entry"}
                </Button>
              </>
            ) : (
              <Button variant="outline" onClick={() => setReversing(true)} disabled={pending}>
                <Undo2 />
                Reverse
              </Button>
            ))}

          {(!entry || entry.status === "draft" || entry.status === "reversed") && !reversing && (
            <Button variant="outline" onClick={() => onBuild()} disabled={pending}>
              <BookOpen />
              {buildMut.isPending ? "Generating…" : entry ? "Regenerate draft" : "Generate draft"}
            </Button>
          )}

          {entry?.status === "draft" && !reversing && (
            <Button onClick={() => onPost()} disabled={pending || !entry.is_balanced}>
              {postMut.isPending ? "Posting…" : "Post to ledger"}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
