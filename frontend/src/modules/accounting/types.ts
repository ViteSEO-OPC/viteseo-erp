export type AccountType = "asset" | "liability" | "equity" | "income" | "expense";

export interface Account {
  id: number;
  code: string;
  name: string;
  type: AccountType;
  parent_id: number | null;
  is_active: boolean;
  /** A real bank or cash account — the only kind Bank Reconciliation can use. */
  is_bank: boolean;
  created_at: string | null;
  updated_at: string | null;
}

export type JournalStatus = "draft" | "posted";
export type JournalSourceType = "manual" | "payroll";

export interface JournalLine {
  id: number;
  account_id: number;
  account_code: string | null;
  account_name: string | null;
  debit: number;
  credit: number;
  memo: string | null;
}

export interface JournalEntry {
  id: number;
  entry_date: string | null;
  reference: string | null;
  memo: string | null;
  source_type: JournalSourceType;
  status: JournalStatus;
  posted_at: string | null;
  lines: JournalLine[];
  created_at: string | null;
}

export interface TrialBalanceRow {
  account_id: number;
  code: string;
  name: string;
  type: AccountType;
  debit: number;
  credit: number;
}

export interface TrialBalance {
  rows: TrialBalanceRow[];
  total_debit: number;
  total_credit: number;
  balanced: boolean;
}

export interface PayrollRun {
  id: number;
  cycle_start: string;
  cycle_end: string;
  status: string;
  employees: number;
  gross: number;
  employer_contributions: number;
  /** Gross + employer contributions — the true company cost. */
  total_cost: number;
  posted: boolean;
}

export interface BudgetRow {
  account_id: number;
  code: string;
  name: string;
  budget: number;
  actual: number;
  variance: number;
}

export interface BudgetVsActual {
  period: string;
  rows: BudgetRow[];
  total_budget: number;
  total_actual: number;
  variance: number;
}

export interface StatementLine {
  code: string;
  name: string;
  amount: number;
  /** Set on expense lines that are employer-share statutory contributions. */
  is_employer_contribution?: boolean;
}

export interface ProfitAndLoss {
  period: string;
  income: StatementLine[];
  expense: StatementLine[];
  total_income: number;
  total_expense: number;
  net_income: number;
  employer_contributions: number;
}

export interface BalanceSheet {
  as_of: string;
  assets: StatementLine[];
  liabilities: StatementLine[];
  equity: StatementLine[];
  net_income: number;
  total_assets: number;
  total_liabilities: number;
  total_equity: number;
  balanced: boolean;
}

export interface InvoiceLineView {
  description: string | null;
  quantity: number;
  unit_price: number;
  account_id: number;
  account_name?: string | null;
  line_total: number;
}

export interface Invoice {
  id: number;
  invoice_no: string;
  customer_name: string;
  issue_date: string | null;
  due_date: string | null;
  description: string | null;
  subtotal: number;
  tax_rate: number;
  tax_amount: number;
  amount: number; // total (subtotal + VAT)
  amount_paid: number;
  status: string; // sent | partial | paid
  /** When the invoice was last emailed to the customer. */
  sent_at: string | null;
  created_at: string | null;
  // Present on the single-invoice fetch (detail / print view):
  contact_email?: string | null;
  contact_phone?: string | null;
  lines?: InvoiceLineView[];
  /** Seller identity for the letterhead (detail / print view only). */
  org?: {
    name: string | null;
    tin: string | null;
    address: string | null;
    is_vat_registered: boolean;
  };
}

/** The org's business identity — invoice letterhead + VAT defaults. */
export interface OrgSettings {
  name: string;
  tin: string | null;
  address: string | null;
  is_vat_registered: boolean;
  default_tax_rate: number | null;
}

export interface Bill {
  id: number;
  bill_no: string;
  vendor_name: string;
  issue_date: string | null;
  due_date: string | null;
  description: string | null;
  subtotal: number;
  tax_rate: number;
  tax_amount: number;
  amount: number; // total (subtotal + VAT)
  amount_paid: number;
  status: string; // awaiting | partial | paid
  created_at: string | null;
}

export interface Contact {
  id: number;
  name: string;
  type: string; // customer | vendor | both
  email: string | null;
  phone: string | null;
  tin: string | null;
  address: string | null;
}

export interface Attachment {
  id: number;
  original_name: string;
  mime_type: string | null;
  size: number;
  created_at: string | null;
}

export interface AgedItem {
  ref: string;
  name: string;
  due_date: string | null;
  days_overdue: number;
  amount: number;
}

export interface AgedBuckets {
  current: number;
  d1_30: number;
  d31_60: number;
  d61_90: number;
  over_90: number;
}

export interface AgedReport {
  items: AgedItem[];
  buckets: AgedBuckets;
  total: number;
}

export interface GeneralLedgerLine {
  date: string;
  reference: string | null;
  memo: string | null;
  debit: number;
  credit: number;
  balance: number;
}

export interface GeneralLedger {
  account: { id: number; code: string; name: string; type: string } | null;
  lines: GeneralLedgerLine[];
  closing_balance: number;
}

export interface CashFlowLine {
  date: string;
  reference: string | null;
  description: string | null;
  inflow: number;
  outflow: number;
  balance: number;
}

export interface CashFlow {
  period: string;
  opening_balance: number;
  inflow: number;
  outflow: number;
  net: number;
  closing_balance: number;
  lines: CashFlowLine[];
}

export interface VatReport {
  period: string;
  output_vat: number;
  input_vat: number;
  net_vat_payable: number;
}

/**
 * Notification-bell counts. A key is `null` when the org hasn't availed that
 * function (invoices = `accounting.invoicing`, bills = `accounting.bills`),
 * which is different from `0` — "not availed" vs "nothing outstanding".
 */
export interface AccountingAlerts {
  as_of: string;
  due_soon_days: number;
  overdue_invoices: number | null;
  overdue_invoice_amount: number | null;
  overdue_bills: number | null;
  overdue_bill_amount: number | null;
  bills_due_soon: number | null;
}
