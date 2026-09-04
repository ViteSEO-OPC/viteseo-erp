"use client";

import { useState } from "react";
import Link from "next/link";
import { ArrowLeft, CreditCard } from "lucide-react";

import { RequireAuth, useAuth } from "@/core/auth-context";
import { notify } from "@/core/lib/notify";
import { useConfirm } from "@/core/ui/use-confirm";
import { Modal } from "@/core/ui/modal";
import {
  useOrgs,
  useActivateOrg,
  useSuspendOrg,
  useResumeOrg,
  useAdjustOrg,
  useSetSeats,
  useRevenueSchedules,
  useOrgPayments,
  useReversePayment,
  type OrgRow,
  type OrgSubscription,
  type PaymentRow,
} from "@/modules/admin/onboarding";

export default function BillingPage() {
  return (
    <RequireAuth>
      <BillingContent />
    </RequireAuth>
  );
}

const STATUS_STYLE: Record<string, string> = {
  active: "bg-brand/10 text-brand",
  trialing: "bg-warning/15 text-warning",
  suspended: "bg-destructive/10 text-destructive",
  pending: "bg-muted text-muted-foreground",
  cancelled: "bg-muted text-muted-foreground",
};

function daysLeft(iso: string | null): number {
  if (!iso) return 0;
  return Math.max(0, Math.ceil((new Date(iso).getTime() - Date.now()) / 86_400_000));
}

// Seats: base 10 free, bought/removed a block of 10 at a time (working default
// price — mirrors backend App\Support\Seats::BLOCK_PRICE).
const SEAT_BLOCK = 10;
const SEAT_BLOCK_PRICE = 499;

function BillingContent() {
  const { roles } = useAuth();
  const isSuperAdmin = (roles as unknown as string[]).includes("super_admin");

  const { data: orgs, isLoading } = useOrgs();
  const activate = useActivateOrg();
  const suspend = useSuspendOrg();
  const resume = useResumeOrg();
  const adjust = useAdjustOrg();
  const setSeats = useSetSeats();
  const { confirm, confirmDialog } = useConfirm();
  const [months, setMonths] = useState<Record<number, number>>({});
  // The org whose paid-until date is being corrected (drives the Adjust modal).
  const [adjusting, setAdjusting] = useState<{ orgId: number; name: string; date: string } | null>(null);
  // The org whose payment history is open (drives the Payments modal).
  const [history, setHistory] = useState<{ orgId: number; name: string } | null>(null);

  if (!isSuperAdmin) {
    return (
      <div className="grid min-h-screen place-items-center bg-background p-6 text-center">
        <div>
          <p className="text-h3 font-bold text-foreground">Super admin only</p>
          <Link href="/" className="mt-4 inline-block text-sm font-semibold text-brand hover:underline">
            ← Back to launcher
          </Link>
        </div>
      </div>
    );
  }

  const monthsFor = (id: number) => months[id] ?? 1;

  const isExpiringSoon = (s: OrgRow["subscription"]) =>
    !!s && (s.status === "active" || s.status === "trialing") && !!s.current_period_end && daysLeft(s.current_period_end) <= 3;

  const list = orgs ?? [];
  const counts = {
    active: list.filter((o) => o.subscription?.status === "active").length,
    trialing: list.filter((o) => o.subscription?.status === "trialing").length,
    suspended: list.filter((o) => o.subscription?.status === "suspended").length,
    expiring: list.filter((o) => isExpiringSoon(o.subscription)).length,
  };

  return (
    <div className="min-h-screen bg-background text-foreground">
      {confirmDialog}

      <Modal
        open={adjusting !== null}
        onClose={() => setAdjusting(null)}
        title={adjusting ? `Adjust ${adjusting.name}` : "Adjust"}
        footer={
          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => setAdjusting(null)} className="rounded-lg border border-border px-4 py-2 text-sm font-semibold text-foreground hover:bg-muted/40">
              Cancel
            </button>
            <button
              type="button"
              disabled={adjust.isPending || !adjusting?.date}
              onClick={() =>
                adjusting &&
                adjust.mutate(
                  { orgId: adjusting.orgId, current_period_end: adjusting.date },
                  { onSuccess: () => { setAdjusting(null); notify.success("Paid-until date updated."); } },
                )
              }
              className="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-foreground hover:opacity-90 disabled:opacity-50"
            >
              {adjust.isPending ? "Saving…" : "Save date"}
            </button>
          </div>
        }
      >
        <label className="grid gap-1.5">
          <span className="text-sm font-medium text-foreground">Paid until</span>
          <input
            type="date"
            value={adjusting?.date ?? ""}
            onChange={(e) => setAdjusting((a) => (a ? { ...a, date: e.target.value } : a))}
            className="h-10 w-full rounded-lg border border-input bg-transparent px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
          />
          <span className="text-caption text-muted-foreground">
            Corrects a mistaken or duplicate payment. A future date keeps access active; a past date suspends it.
          </span>
        </label>
      </Modal>

      {history ? (
        <PaymentHistoryModal orgId={history.orgId} name={history.name} onClose={() => setHistory(null)} />
      ) : null}

      <div className="mx-auto w-full max-w-4xl px-6 py-10">
        <Link
          href="/"
          className="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
        >
          <ArrowLeft className="size-4" /> Launcher
        </Link>

        <div className="mt-4 flex items-center gap-2.5">
          <span className="flex size-9 items-center justify-center rounded-lg bg-brand text-brand-foreground">
            <CreditCard className="size-5" />
          </span>
          <h1 className="text-h2 font-bold tracking-tight">Billing</h1>
        </div>
        <p className="mt-2 text-body text-muted-foreground">
          Record a customer’s payment to activate or renew their access. Prepaid —
          no invoices pile up: paid = active, unpaid = suspended.
        </p>

        {/* Summary strip */}
        {orgs ? (
          <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Summary label="Paid (active)" count={counts.active} tone="brand" />
            <Summary label="On free trial" count={counts.trialing} tone="warning" />
            <Summary label="Expiring ≤3 days" count={counts.expiring} tone="warning" />
            <Summary label="Suspended" count={counts.suspended} tone="destructive" />
          </div>
        ) : null}

        <div className="mt-6 overflow-x-auto rounded-card border border-border bg-card">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border">
                <th className="px-4 py-2.5 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">Organization</th>
                <th className="px-4 py-2.5 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">Plan</th>
                <th className="px-4 py-2.5 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">Status</th>
                <th className="px-4 py-2.5 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">Period ends</th>
                <th className="px-4 py-2.5 text-right text-label font-bold uppercase tracking-wide text-muted-foreground">Actions</th>
              </tr>
            </thead>
            <tbody>
              {isLoading ? (
                <tr><td colSpan={5} className="px-4 py-6 text-center text-muted-foreground">Loading…</td></tr>
              ) : (orgs ?? []).length === 0 ? (
                <tr><td colSpan={5} className="px-4 py-6 text-center text-muted-foreground">No organizations yet.</td></tr>
              ) : (
                (orgs ?? []).map((org: OrgRow) => {
                  const sub: OrgSubscription | null = org.subscription;
                  const busy =
                    (activate.isPending && activate.variables?.orgId === org.id) ||
                    (suspend.isPending && suspend.variables === org.id) ||
                    (resume.isPending && resume.variables === org.id);
                  return (
                    <tr key={org.id} className="border-b border-border last:border-0">
                      <td className="px-4 py-3 font-medium text-foreground">{org.name}</td>
                      <td className="px-4 py-3 text-muted-foreground">
                        <div>{sub?.plan ?? "—"}</div>
                        {sub ? (
                          <div className="mt-1 flex items-center gap-1.5">
                            <span className="text-caption text-muted-foreground">
                              {sub.seats_used}/{sub.seat_limit} seats
                            </span>
                            <button
                              type="button"
                              title={`Add ${SEAT_BLOCK} seats (+${peso(SEAT_BLOCK_PRICE)}/mo)`}
                              disabled={setSeats.isPending}
                              onClick={() =>
                                confirm({
                                  title: `Add ${SEAT_BLOCK} seats to ${org.name}?`,
                                  description: `New cap: ${sub.seat_limit + SEAT_BLOCK} seats. Monthly price +${peso(SEAT_BLOCK_PRICE)} → ${peso(sub.monthly_price + SEAT_BLOCK_PRICE)}/mo.`,
                                  confirmLabel: "Add seats",
                                  onConfirm: () =>
                                    setSeats.mutate(
                                      { orgId: org.id, seat_limit: sub.seat_limit + SEAT_BLOCK },
                                      { onSuccess: () => notify.success(`Added ${SEAT_BLOCK} seats.`) },
                                    ),
                                })
                              }
                              className="flex size-5 items-center justify-center rounded border border-border text-caption font-bold text-foreground hover:bg-muted/40 disabled:opacity-50"
                            >
                              +
                            </button>
                            <button
                              type="button"
                              title={
                                sub.seat_limit - SEAT_BLOCK < sub.seats_used
                                  ? "Remove members first"
                                  : `Remove ${SEAT_BLOCK} seats (−${peso(SEAT_BLOCK_PRICE)}/mo)`
                              }
                              disabled={
                                setSeats.isPending ||
                                sub.seat_limit <= SEAT_BLOCK ||
                                sub.seat_limit - SEAT_BLOCK < sub.seats_used
                              }
                              onClick={() =>
                                confirm({
                                  title: `Remove ${SEAT_BLOCK} seats from ${org.name}?`,
                                  description: `New cap: ${sub.seat_limit - SEAT_BLOCK} seats. Monthly price −${peso(SEAT_BLOCK_PRICE)} → ${peso(sub.monthly_price - SEAT_BLOCK_PRICE)}/mo.`,
                                  confirmLabel: "Remove seats",
                                  onConfirm: () =>
                                    setSeats.mutate(
                                      { orgId: org.id, seat_limit: sub.seat_limit - SEAT_BLOCK },
                                      { onSuccess: () => notify.success(`Removed ${SEAT_BLOCK} seats.`) },
                                    ),
                                })
                              }
                              className="flex size-5 items-center justify-center rounded border border-border text-caption font-bold text-foreground hover:bg-muted/40 disabled:opacity-40"
                            >
                              −
                            </button>
                          </div>
                        ) : null}
                      </td>
                      <td className="px-4 py-3">
                        {sub ? (
                          <span className={`rounded-full px-2 py-0.5 text-caption font-semibold capitalize ${STATUS_STYLE[sub.status] ?? "bg-muted text-muted-foreground"}`}>
                            {sub.status}
                          </span>
                        ) : <span className="text-muted-foreground">—</span>}
                      </td>
                      <td className="px-4 py-3 text-muted-foreground">
                        {sub?.current_period_end ? (
                          <span className="inline-flex items-center gap-2">
                            {`${new Date(sub.current_period_end).toLocaleDateString("en-PH")} (${daysLeft(sub.current_period_end)}d)`}
                            {isExpiringSoon(sub) ? (
                              <span className="rounded-full bg-warning/15 px-1.5 py-0.5 text-caption font-semibold text-warning">Expiring soon</span>
                            ) : null}
                          </span>
                        ) : "—"}
                      </td>
                      <td className="px-4 py-3">
                        {sub ? (
                          <div className="flex flex-wrap items-center justify-end gap-2 whitespace-nowrap">
                            <div className="flex items-center overflow-hidden rounded-lg border border-brand">
                              <select
                                value={monthsFor(org.id)}
                                onChange={(e) => setMonths((m) => ({ ...m, [org.id]: Number(e.target.value) }))}
                                className="h-8 border-r border-brand/40 bg-transparent px-2 text-caption outline-none"
                                title="Months paid"
                              >
                                {[1, 3, 6, 12].map((n) => (
                                  <option key={n} value={n}>{n} mo</option>
                                ))}
                              </select>
                              <button
                                type="button"
                                disabled={busy}
                                onClick={() => {
                                  const n = monthsFor(org.id);
                                  confirm({
                                    title: `Record a ${n}-month payment for ${org.name}?`,
                                    description: "This extends their paid period.",
                                    confirmLabel: "Record payment",
                                    destructive: false,
                                    onConfirm: () => activate.mutate({ orgId: org.id, months: n }),
                                  });
                                }}
                                className="h-8 bg-brand px-3 text-caption font-semibold text-brand-foreground hover:opacity-90 disabled:opacity-50"
                              >
                                Record payment
                              </button>
                            </div>
                            {sub.status === "suspended" ? (
                              <button
                                type="button"
                                disabled={busy}
                                title="Restore access without charging (uses remaining paid time)"
                                onClick={() =>
                                  resume.mutate(org.id, {
                                    onError: () => notify.error("Can’t resume — the paid period has ended. Record a payment instead."),
                                  })
                                }
                                className="h-8 rounded-lg border border-border px-3 text-caption font-semibold text-brand hover:bg-brand/5 disabled:opacity-50"
                              >
                                Resume
                              </button>
                            ) : sub.is_active ? (
                              <button
                                type="button"
                                disabled={busy}
                                onClick={() =>
                                  confirm({
                                    title: `Suspend ${org.name}?`,
                                    description: "Access pauses immediately. Paid time is kept — you can Resume for free.",
                                    confirmLabel: "Suspend",
                                    onConfirm: () => suspend.mutate(org.id),
                                  })
                                }
                                className="h-8 rounded-lg border border-border px-3 text-caption font-semibold text-destructive hover:bg-destructive/5 disabled:opacity-50"
                              >
                                Suspend
                              </button>
                            ) : null}
                            <button
                              type="button"
                              title="Payment history — reverse a mistaken/duplicate payment"
                              onClick={() => setHistory({ orgId: org.id, name: org.name })}
                              className="h-8 rounded-lg border border-border px-3 text-caption font-semibold text-muted-foreground hover:bg-muted/40"
                            >
                              History
                            </button>
                            <button
                              type="button"
                              disabled={busy}
                              title="Correct the paid-until date (fix a mistaken or duplicate payment)"
                              onClick={() =>
                                setAdjusting({
                                  orgId: org.id,
                                  name: org.name,
                                  date: sub.current_period_end ? sub.current_period_end.slice(0, 10) : new Date().toISOString().slice(0, 10),
                                })
                              }
                              className="h-8 rounded-lg border border-border px-3 text-caption font-semibold text-muted-foreground hover:bg-muted/40 disabled:opacity-50"
                            >
                              Adjust
                            </button>
                          </div>
                        ) : (
                          <p className="text-right text-caption text-muted-foreground">Provider</p>
                        )}
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>

        <DeferredRevenueSection />
      </div>
    </div>
  );
}

const peso = (n: number) => `₱${n.toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/** Deferred revenue from multi-month prepayments, recognized monthly. */
function DeferredRevenueSection() {
  const { data } = useRevenueSchedules();
  if (!data || (data.schedules.length === 0 && data.unearned_balance === 0)) return null;

  return (
    <section className="mt-10">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-h3 font-bold text-foreground">Deferred revenue</h2>
        <p className="text-sm text-muted-foreground">
          Unearned Revenue balance: <span className="font-semibold tabular-nums text-foreground">{peso(data.unearned_balance)}</span>
        </p>
      </div>
      <p className="mt-1 text-caption text-muted-foreground">
        Multi-month prepayments are recognized one month at a time, so each month’s P&amp;L is accurate.
      </p>

      <div className="mt-3 overflow-x-auto rounded-card border border-border bg-card">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-border text-label uppercase tracking-wide text-muted-foreground">
              <th className="px-4 py-2.5 text-left font-bold">Source</th>
              <th className="px-4 py-2.5 text-right font-bold">Per month</th>
              <th className="px-4 py-2.5 text-right font-bold">Recognized</th>
              <th className="px-4 py-2.5 text-right font-bold">Still deferred</th>
              <th className="px-4 py-2.5 text-left font-bold">Next</th>
              <th className="px-4 py-2.5 text-left font-bold">Status</th>
            </tr>
          </thead>
          <tbody>
            {data.schedules.length === 0 ? (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-muted-foreground">No deferred revenue right now.</td></tr>
            ) : (
              data.schedules.map((s) => (
                <tr key={s.id} className="border-b border-border last:border-0">
                  <td className="px-4 py-2.5 text-foreground">{s.description ?? "—"}</td>
                  <td className="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{peso(s.monthly_amount)}</td>
                  <td className="px-4 py-2.5 text-right tabular-nums text-foreground">{s.recognized_count} / {s.months}</td>
                  <td className="px-4 py-2.5 text-right tabular-nums text-foreground">{peso(s.deferred_remaining)}</td>
                  <td className="px-4 py-2.5 text-muted-foreground">{s.next_recognition_date ?? "—"}</td>
                  <td className="px-4 py-2.5">
                    <span className={`rounded-full px-2 py-0.5 text-caption font-semibold capitalize ${s.status === "active" ? "bg-brand/10 text-brand" : "bg-muted text-muted-foreground"}`}>
                      {s.status}
                    </span>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </section>
  );
}

/** Payment history for one org, with reverse (undo) on the most recent payment. */
function PaymentHistoryModal({ orgId, name, onClose }: { orgId: number; name: string; onClose: () => void }) {
  const { data, isLoading } = useOrgPayments(orgId);
  const reverse = useReversePayment();
  const { confirm, confirmDialog } = useConfirm();

  return (
    <Modal open onClose={onClose} title={`Payments — ${name}`} width={640}>
      {confirmDialog}
      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : (data ?? []).length === 0 ? (
        <p className="text-sm text-muted-foreground">No recorded payments yet.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-line text-label uppercase tracking-wide text-muted-foreground">
                <th className="py-2 pr-3 text-left font-bold">Recorded</th>
                <th className="py-2 pr-3 text-right font-bold">Months</th>
                <th className="py-2 pr-3 text-right font-bold">Amount</th>
                <th className="py-2 text-right font-bold">Status</th>
              </tr>
            </thead>
            <tbody>
              {(data ?? []).map((p: PaymentRow) => (
                <tr key={p.id} className="border-b border-line last:border-0">
                  <td className="py-2 pr-3 text-foreground">
                    {p.recorded_at ? new Date(p.recorded_at).toLocaleString("en-PH") : "—"}
                  </td>
                  <td className="py-2 pr-3 text-right tabular-nums text-muted-foreground">{p.months}</td>
                  <td className="py-2 pr-3 text-right tabular-nums text-foreground">{peso(p.total_amount)}</td>
                  <td className="py-2 text-right">
                    {p.status === "reversed" ? (
                      <span className="rounded-full bg-muted px-2 py-0.5 text-caption font-semibold text-muted-foreground">Reversed</span>
                    ) : p.can_reverse ? (
                      <button
                        type="button"
                        disabled={reverse.isPending}
                        onClick={() =>
                          confirm({
                            title: "Reverse this payment?",
                            description: "Rolls back their paid period and reverses it in your books. Refund any money separately.",
                            confirmLabel: "Reverse payment",
                            onConfirm: () =>
                              reverse.mutate(p.id, {
                                onSuccess: () => notify.success("Payment reversed."),
                                onError: () => notify.error("Only the most recent payment can be reversed."),
                              }),
                          })
                        }
                        className="rounded-lg border border-border px-2.5 py-1 text-caption font-semibold text-destructive hover:bg-destructive/5 disabled:opacity-50"
                      >
                        Reverse
                      </button>
                    ) : (
                      <span className="text-caption text-muted-foreground">—</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <p className="mt-3 text-caption text-muted-foreground">
            Only the most recent payment can be reversed (undo). The actual refund (GCash/bank) is done separately.
          </p>
        </div>
      )}
    </Modal>
  );
}

function Summary({ label, count, tone }: { label: string; count: number; tone: "brand" | "warning" | "destructive" }) {
  const toneCls =
    tone === "brand" ? "text-brand" : tone === "warning" ? "text-warning" : "text-destructive";
  return (
    <div className="rounded-card border border-border bg-card p-4">
      <p className="text-caption uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className={`mt-1 text-h2 font-bold tabular-nums ${toneCls}`}>{count}</p>
    </div>
  );
}
