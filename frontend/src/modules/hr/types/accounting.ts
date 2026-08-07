/**
 * FIN-043 / MF-05 — payroll's double-entry, and FIN-017 / MF-08 — the monthly agency filings.
 *
 * `payroll_role` is the join between payroll and the ledger: it says which payroll figure an account
 * receives. The generator refuses to build an entry until every role has one, because a half-mapped
 * chart would post a journal that balances only because the unmapped figures were dropped.
 */
export type PayrollRole =
  | "salaries_expense"
  | "employer_contributions_expense"
  | "sss_payable"
  | "philhealth_payable"
  | "pagibig_payable"
  | "withholding_tax_payable"
  | "other_deductions_payable"
  | "net_pay_payable";

export interface GlAccount {
  id: number;
  code: string;
  name: string;
  type: "expense" | "liability" | "asset";
  /** Null = held in the chart but never posted to by payroll. */
  payroll_role: PayrollRole | null;
  is_active: boolean;
}

export interface GlAccountInput {
  code: string;
  name: string;
  type: "expense" | "liability" | "asset";
  payroll_role?: PayrollRole | null;
  is_active?: boolean;
}

export interface ChartOfAccounts {
  accounts: GlAccount[];
  /** Role key → human label, from the server so the UI holds no second copy of the list. */
  roles: Record<string, string>;
  types: string[];
  /** Roles with no account yet. Non-empty means the journal generator will refuse. */
  unmapped_roles: PayrollRole[];
}

export interface JournalLine {
  account_code: string | null;
  account_name: string | null;
  account_type: string | null;
  debit: number;
  credit: number;
  memo: string | null;
}

export interface JournalEntry {
  id: number;
  payroll_run_id: number;
  /** The date it hits the books — not the run date and not the pay date. */
  posting_date: string;
  reference: string | null;
  /** draft = editable, not on the books. posted = immutable. reversed = cancelled by a mirror. */
  status: "draft" | "posted" | "reversed";
  posted_at: string | null;
  posted_by: string | null;
  /** Set on a mirror entry, pointing at what it reverses. */
  reversal_of_id: number | null;
  note: string | null;
  total_debit: number;
  total_credit: number;
  is_balanced: boolean;
  lines: JournalLine[];
}

export interface JournalRunStatus {
  entry: JournalEntry | null;
  default_posting_date: string;
}
