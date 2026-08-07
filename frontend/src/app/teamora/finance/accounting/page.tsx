"use client";

import { useState } from "react";
import { Plus, Trash2, Download, AlertTriangle } from "lucide-react";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { PageHeader } from "@/modules/hr/components/page-header";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { manilaToday } from "@/modules/hr/lib/manila-date";
import {
  useGlAccounts,
  useCreateGlAccount,
  useDeleteGlAccount,
} from "@/modules/hr/hooks/use-accounting";
import { exportJournal, exportRemittance } from "@/modules/hr/api/accounting";
import type { GlAccount, PayrollRole } from "@/modules/hr/types/accounting";

/** Show what the server said — "code already taken", "role already mapped" are both actionable. */
function serverMessage(err: unknown, fallback: string): string {
  if (err instanceof AxiosError && err.response?.status === 422) {
    const errors = err.response.data?.errors ?? {};
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && typeof first[0] === "string") return first[0];
    return err.response.data?.message ?? fallback;
  }
  return fallback;
}

/** The first of the current Manila month, as YYYY-MM. */
function thisMonth(): string {
  return manilaToday().slice(0, 7);
}

/**
 * Accounting — the chart of accounts and the two exports (FIN-043 / MF-05, FIN-017 / MF-08).
 *
 * NOTHING HERE IS SEEDED, which is the point. Account codes come from the company's existing ledger, and
 * shipping a plausible-looking "5100 — Salaries Expense" would produce journals that post cleanly and
 * reconcile to nothing. So the page opens empty, states which payroll figures still need an account, and
 * the journal generator refuses until every one is mapped.
 *
 * THE TWO EXPORTS ANSWER DIFFERENT QUESTIONS. The journal export is what the general ledger is loaded
 * from — one row per debit and credit, drafts excluded because a draft is not on the books. The remittance
 * export is the monthly agency filing: per employee, per agency, per MONTH, at full precision, aggregated
 * across however many runs the month contained. That last part is the gap FIN-017 actually described —
 * per-run figures already existed, but a semi-monthly month is two runs and the form wants one number.
 */
export default function AccountingPage() {
  const { data, isLoading, isError } = useGlAccounts();
  const createMut = useCreateGlAccount();
  const deleteMut = useDeleteGlAccount();

  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [type, setType] = useState<"expense" | "liability" | "asset">("expense");
  const [role, setRole] = useState<string>("");

  const [from, setFrom] = useState(`${thisMonth()}-01`);
  const [to, setTo] = useState(manilaToday());
  const [month, setMonth] = useState(thisMonth());
  const [agency, setAgency] = useState("sss");
  const [exporting, setExporting] = useState(false);

  const roles = data?.roles ?? {};
  const unmapped = data?.unmapped_roles ?? [];

  async function onAdd() {
    if (!code.trim() || !name.trim()) {
      toast.error("A code and a name are required.");
      return;
    }
    try {
      await createMut.mutateAsync({
        code: code.trim(),
        name: name.trim(),
        type,
        payroll_role: (role || null) as PayrollRole | null,
      });
      toast.success("Account added");
      setCode("");
      setName("");
      setRole("");
    } catch (err) {
      toast.error(serverMessage(err, "Could not add the account."));
    }
  }

  async function onDelete(account: GlAccount) {
    try {
      await deleteMut.mutateAsync(account.id);
      toast.success("Account removed");
    } catch {
      toast.error("Could not remove the account.");
    }
  }

  async function onExport(fn: () => Promise<void>, label: string) {
    setExporting(true);
    try {
      await fn();
      toast.success(`${label} downloaded`);
    } catch {
      toast.error(`Could not export the ${label.toLowerCase()}.`);
    } finally {
      setExporting(false);
    }
  }

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Accounting"
        subtitle="Map payroll to your chart of accounts, then export the ledger journal and the monthly agency remittances."
      />

      <div className="mt-8 space-y-8">
        {/* --- Chart of accounts --- */}
        <section className="space-y-3">
          <div>
            <h2 className="text-h3 font-bold text-foreground">Chart of accounts</h2>
            <p className="mt-1 max-w-3xl text-caption text-muted-foreground">
              Enter the accounts from your own ledger and say which payroll figure each one receives.
              Nothing is pre-filled on purpose — a guessed account code posts a journal that balances
              and reconciles to nothing.
            </p>
          </div>

          {unmapped.length > 0 && (
            <div className="flex items-start gap-2 rounded-lg border border-warning bg-warning-subtle p-3">
              <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden />
              <p className="text-xs text-foreground">
                <strong>Payroll cannot post to the ledger yet.</strong> Still needs an account for:{" "}
                {unmapped.map((r) => roles[r] ?? r).join(", ")}.
              </p>
            </div>
          )}

          {isLoading ? (
            <p className="text-sm text-muted-foreground">Loading the chart…</p>
          ) : isError ? (
            <p className="text-sm text-destructive">Could not load the chart of accounts.</p>
          ) : (
            <div className="overflow-x-auto rounded-lg border border-border bg-card">
              <table className="w-full min-w-[720px] text-sm">
                <thead>
                  <tr className="border-b border-border bg-muted/60 text-label font-bold uppercase tracking-wide text-muted-foreground">
                    <th className="px-4 py-2.5 text-left">Code</th>
                    <th className="px-4 py-2.5 text-left">Account</th>
                    <th className="px-4 py-2.5 text-left">Type</th>
                    <th className="px-4 py-2.5 text-left">Receives</th>
                    <th className="px-4 py-2.5 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {(data?.accounts ?? []).length === 0 ? (
                    <tr>
                      <td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">
                        No accounts yet. Add your first one below.
                      </td>
                    </tr>
                  ) : (
                    data!.accounts.map((a) => (
                      <tr key={a.id} className="border-b border-border last:border-0">
                        <td className="px-4 py-2.5 font-medium text-foreground">{a.code}</td>
                        <td className="px-4 py-2.5 text-foreground">{a.name}</td>
                        <td className="px-4 py-2.5 capitalize text-muted-foreground">{a.type}</td>
                        <td className="px-4 py-2.5 text-muted-foreground">
                          {a.payroll_role ? (roles[a.payroll_role] ?? a.payroll_role) : "—"}
                        </td>
                        <td className="px-4 py-2.5 text-right">
                          <Button size="icon-sm" variant="ghost" onClick={() => onDelete(a)}>
                            <Trash2 />
                            <span className="sr-only">Remove</span>
                          </Button>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          )}

          {/* Add form */}
          <div className="grid grid-cols-1 gap-3 rounded-lg border border-border bg-card p-4 sm:grid-cols-5">
            <div className="space-y-1.5">
              <Label htmlFor="gl-code">Code</Label>
              <Input id="gl-code" value={code} onChange={(e) => setCode(e.target.value)} placeholder="5100" />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="gl-name">Account name</Label>
              <Input id="gl-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Salaries & Wages" />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="gl-type">Type</Label>
              <SimpleSelect
                value={type}
                onValueChange={(v) => setType(v as "expense" | "liability" | "asset")}
                options={[
                  { value: "expense", label: "Expense" },
                  { value: "liability", label: "Liability" },
                  { value: "asset", label: "Asset" },
                ]}
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="gl-role">Receives</Label>
              <SimpleSelect
                value={role}
                onValueChange={setRole}
                placeholder="Nothing from payroll"
                options={Object.entries(roles).map(([value, label]) => ({ value, label }))}
              />
            </div>
            <div className="flex items-end">
              <Button onClick={() => onAdd()} disabled={createMut.isPending} className="w-full">
                <Plus />
                {createMut.isPending ? "Adding…" : "Add account"}
              </Button>
            </div>
          </div>
        </section>

        {/* --- Journal export --- */}
        <section className="space-y-3">
          <div>
            <h2 className="text-h3 font-bold text-foreground">Ledger journal export</h2>
            <p className="mt-1 max-w-3xl text-caption text-muted-foreground">
              One row per debit and credit, at full precision, for everything posted in the range.
              Drafts are excluded — a draft is not on the books.
            </p>
          </div>
          <div className="flex flex-wrap items-end gap-3 rounded-lg border border-border bg-card p-4">
            <div className="flex items-center gap-2">
              <Label htmlFor="je-from">From</Label>
              <DatePicker id="je-from" value={from} onChange={(v) => setFrom(v)} className="w-40" />
            </div>
            <div className="flex items-center gap-2">
              <Label htmlFor="je-to">To</Label>
              <DatePicker id="je-to" value={to} onChange={(v) => setTo(v)} className="w-40" />
            </div>
            <Button
              variant="outline"
              disabled={exporting}
              onClick={() => onExport(() => exportJournal(from, to), "Journal")}
            >
              <Download />
              Export journal
            </Button>
          </div>
        </section>

        {/* --- Remittance export --- */}
        <section className="space-y-3">
          <div>
            <h2 className="text-h3 font-bold text-foreground">Monthly remittance export</h2>
            <p className="mt-1 max-w-3xl text-caption text-muted-foreground">
              Per employee, per agency, for the whole month at full precision — including both halves of
              each contribution and the government number each form needs. Aggregated across every run
              in the month, so a semi-monthly schedule does not mean adding two files by hand. Counts
              <strong> disbursed</strong> payslips only.
            </p>
          </div>
          <div className="flex flex-wrap items-end gap-3 rounded-lg border border-border bg-card p-4">
            <div className="space-y-1.5">
              <Label htmlFor="rm-month">Month</Label>
              <Input
                id="rm-month"
                type="month"
                value={month}
                onChange={(e) => setMonth(e.target.value)}
                className="w-40"
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="rm-agency">Agency</Label>
              <SimpleSelect
                value={agency}
                onValueChange={setAgency}
                className="w-44"
                options={[
                  { value: "sss", label: "SSS" },
                  { value: "philhealth", label: "PhilHealth" },
                  { value: "pagibig", label: "Pag-IBIG" },
                  { value: "tax", label: "Withholding tax (BIR)" },
                ]}
              />
            </div>
            <Button
              variant="outline"
              disabled={exporting}
              onClick={() => onExport(() => exportRemittance(month, agency), "Remittance")}
            >
              <Download />
              Export remittance
            </Button>
          </div>
        </section>
      </div>
    </div>
  );
}
