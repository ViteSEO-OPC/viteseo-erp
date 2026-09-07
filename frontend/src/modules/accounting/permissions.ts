import { useAuth } from "@/core/auth-context";

/**
 * Accounting permission flags for the current user — used to hide/disable
 * actions the user isn't allowed to perform (the backend enforces them too).
 */
export function useAccountingPerms() {
  const { permissions } = useAuth();
  const list = permissions as unknown as string[];
  const has = (p: string) => list.includes(p);

  return {
    /** Create entries / invoices / bills / contacts, mark paid, upload. */
    canManage: has("accounting.journal.create"),
    canPostPayroll: has("accounting.payroll.post"),
    canPostJournal: has("accounting.journal.post"),
    canManageBudget: has("accounting.budgets.manage"),
    canManageAccounts: has("accounting.accounts.create"),
    canReverse: has("accounting.journal.reverse"),
  };
}
