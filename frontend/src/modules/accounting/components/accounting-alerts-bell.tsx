"use client";

import { useState } from "react";
import Link from "next/link";
import { Bell, AlertTriangle, CalendarClock, ReceiptText } from "lucide-react";

import { useAccountingAlerts } from "@/modules/accounting/hooks/useAlerts";
import { formatPeso } from "@/core/lib/format";

/**
 * Accounting's own top-bar bell — module-aware alerts (overdue receivables,
 * overdue payables, bills falling due this week) instead of the platform's
 * HR-oriented notification center. Injected via AppShell's `rightSlot`.
 *
 * A count of `null` means the org hasn't availed that function, so the row is
 * skipped entirely rather than shown as a reassuring zero.
 */
export function AccountingAlertsBell() {
  const { data, isLoading, isError } = useAccountingAlerts();
  const [open, setOpen] = useState(false);

  const overdueInvoices = data?.overdue_invoices ?? 0;
  const overdueAmount = data?.overdue_invoice_amount ?? 0;
  const overdueBills = data?.overdue_bills ?? 0;
  const dueSoon = data?.bills_due_soon ?? 0;
  const total = overdueInvoices + overdueBills + dueSoon;

  const alerts = [
    {
      show: overdueInvoices > 0,
      icon: AlertTriangle,
      label: `${overdueInvoices} overdue invoice${overdueInvoices === 1 ? "" : "s"} · ${formatPeso(overdueAmount)}`,
      href: "/accounting/reports/aged-receivables",
    },
    {
      show: overdueBills > 0,
      icon: ReceiptText,
      label: `${overdueBills} overdue bill${overdueBills === 1 ? "" : "s"}`,
      href: "/accounting/reports/aged-payables",
    },
    {
      show: dueSoon > 0,
      icon: CalendarClock,
      label: `${dueSoon} bill${dueSoon === 1 ? "" : "s"} due this week`,
      href: "/accounting/bills",
    },
  ].filter((a) => a.show);

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="relative flex size-9 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted"
        aria-label="Accounting alerts"
      >
        <Bell className="size-5" />
        {total > 0 && (
          <span className="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold text-white">
            {total}
          </span>
        )}
      </button>

      {open && (
        <>
          <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
          <div className="absolute right-0 top-full z-20 mt-2 w-[min(20rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-border bg-popover shadow-lg">
            <div className="border-b border-border px-4 py-3">
              <p className="text-sm font-semibold text-foreground">Accounting alerts</p>
            </div>
            <div className="max-h-96 overflow-y-auto">
              {/* "Books are current" is a CLAIM. Made on a failed request it is a
                  false one, and this is the one surface a client checks instead
                  of looking — the same shape that let three broken screens read
                  as merely unused for two days. Say nothing was loaded. */}
              {isError ? (
                <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                  Couldn’t load alerts. Please try again.
                </p>
              ) : isLoading ? (
                <p className="px-4 py-8 text-center text-sm text-muted-foreground">Loading…</p>
              ) : alerts.length === 0 ? (
                <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                  Nothing overdue — books are current. 🎉
                </p>
              ) : (
                alerts.map((a, i) => (
                  <Link
                    key={i}
                    href={a.href}
                    onClick={() => setOpen(false)}
                    className="flex items-center gap-3 border-b border-border px-4 py-3 last:border-0 hover:bg-muted"
                  >
                    <a.icon className="size-4 shrink-0 text-danger" />
                    <span className="text-sm font-medium text-foreground">{a.label}</span>
                  </Link>
                ))
              )}
            </div>
          </div>
        </>
      )}
    </div>
  );
}
