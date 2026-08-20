"use client";

import { useState } from "react";
import { Info } from "lucide-react";

import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { manilaToday } from "@/modules/hr/lib/manila-date";
import { useTaxTable } from "@/modules/hr/hooks/use-tax-table";

const peso = new Intl.NumberFormat("en-PH", {
  style: "currency",
  currency: "PHP",
  minimumFractionDigits: 2,
});

/** "₱250,000.00" for a floor, "and over" for the open-ended top band. */
function range(min: number, max: number | null): string {
  return max === null
    ? `${peso.format(min)} and over`
    : `${peso.format(min)} – ${peso.format(max)}`;
}

/** The withholding-tax table, readable. */
export function TaxTableDialog({
  open,
  onOpenChange,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const [asOf, setAsOf] = useState(manilaToday());
  const { data, isLoading, isError } = useTaxTable(open ? asOf : "");

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-[620px]">
        <DialogHeader>
          <DialogTitle>Withholding tax table</DialogTitle>
          <DialogDescription>
            The annual bands payroll withholds against. Read-only — these come from the BIR schedule
            and are loaded by migration, not edited here.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="flex items-center gap-3">
            <Label htmlFor="tax-as-of" className="shrink-0">
              In force on
            </Label>
            <DatePicker
              id="tax-as-of"
              value={asOf}
              onChange={(v) => setAsOf(v)}
              className="w-44"
            />
          </div>

          {isLoading ? (
            <p className="text-sm text-muted-foreground">Loading the table…</p>
          ) : isError || !data ? (
            <p className="text-sm text-destructive">Could not load the tax table.</p>
          ) : data.bands.length === 0 ? (
            <p className="text-sm text-destructive">
              No tax table is loaded. Payroll cannot compute withholding tax without one.
            </p>
          ) : (
            <>
              <div className="overflow-x-auto rounded-lg border border-border">
                <table className="w-full min-w-[520px] text-sm">
                  <thead>
                    <tr className="border-b border-border bg-muted/60 text-label font-bold uppercase tracking-wide text-muted-foreground">
                      <th className="px-3 py-2 text-left">Annual taxable income</th>
                      <th className="px-3 py-2 text-right">Base tax</th>
                      <th className="px-3 py-2 text-right">Plus, on the excess</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.bands.map((b) => (
                      <tr key={b.min_annual} className="border-b border-border last:border-0">
                        <td className="px-3 py-2 text-foreground">
                          {range(b.min_annual, b.max_annual)}
                        </td>
                        <td className="px-3 py-2 text-right text-foreground">
                          {b.base_tax === 0 ? "—" : peso.format(b.base_tax)}
                        </td>
                        <td className="px-3 py-2 text-right text-foreground">
                          {b.rate_on_excess === 0
                            ? "Exempt"
                            : `${(b.rate_on_excess * 100).toFixed(0)}%`}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <p className="text-caption text-muted-foreground">
                {data.effective_from ? (
                  <>
                    Table in force since <strong>{data.effective_from}</strong>
                    {data.note ? ` · ${data.note}` : null}
                  </>
                ) : (
                  "No version covers this date."
                )}
                {data.versions.length > 1 && (
                  <>
                    {" "}
                    Versions loaded: {data.versions.map((v) => v.effective_from).join(", ")}.
                  </>
                )}
              </p>

              {data.is_fallback && (
                <div className="flex items-start gap-2 rounded-lg border border-warning bg-warning-subtle p-3">
                  <Info className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden />
                  <p className="text-xs text-foreground">
                    This date is before any loaded version, so the <strong>earliest</strong> table is
                    shown. Payroll does the same rather than refusing — but a period priced this way
                    was judged against a table that did not formally cover it.
                  </p>
                </div>
              )}

              <div className="rounded-lg border border-border p-3">
                <p className="text-xs text-muted-foreground">
                  Payroll annualises each period&apos;s taxable pay, looks it up here, and divides by
                  the number of periods. Because the table is progressive, that projection
                  over-withholds for anyone whose pay varies — which is why the December run and final
                  pay true the year up, usually as a refund.
                </p>
              </div>
            </>
          )}
        </div>
      </DialogContent>
    </Dialog>
  );
}
