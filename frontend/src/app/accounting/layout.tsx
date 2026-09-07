"use client";

import { RequireAuth } from "@/core/auth-context";
import { AppShell } from "@/core/components/app-shell";
import { SubscriptionGuard } from "@/core/components/subscription-guard";
import { AccountingAlertsBell } from "@/modules/accounting/components/accounting-alerts-bell";
import { AccountingSearch } from "@/modules/accounting/components/accounting-search";

/**
 * Accounting portal shell — the same dark sidebar + top bar as TeamOra/Zerem/
 * Sprintly (logout, switch app, notifications), with the accounting nav set
 * and its own money-aware alerts bell (which replaces the platform HR one).
 */
export default function AccountingLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <RequireAuth>
      <AppShell
        variant="accounting"
        searchSlot={<AccountingSearch />}
        rightSlot={<AccountingAlertsBell />}
      >
        <div className="w-full px-4 py-6 sm:px-6 sm:py-8 lg:px-10">
          <SubscriptionGuard>{children}</SubscriptionGuard>
        </div>
      </AppShell>
    </RequireAuth>
  );
}
