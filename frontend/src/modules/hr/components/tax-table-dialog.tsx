"use client";

import { useMemo, useState } from "react";
import { AlertTriangle, Info, Pencil, Plus, Trash2, Wand2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { notify as toast } from "@/core/lib/notify";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { manilaToday } from "@/modules/hr/lib/manila-date";
import {
  useDeleteTaxVersion,
  useSaveTaxTable,
  useTaxTable,
} from "@/modules/hr/hooks/use-tax-table";
import type { TaxBand, TaxTable } from "@/modules/hr/types/payroll";

const peso = new Intl.NumberFormat("en-PH", {
  style: "currency",
  currency: "PHP",
  minimumFractionDigits: 2,
});

const plain = new Intl.NumberFormat("en-PH", {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

/**
 * A band's bounds, worded the way the BIR schedule words them.
 *
 * The bounds SHARE a number — ₱250,000 is the exempt band's ceiling and the 15% band's floor —
 * because a band is entered only by EXCEEDING its floor, so ₱250,000 itself is exempt. Saying
 * "not over" and "over" is what makes that unambiguous without inventing a centavo of gap.
 */
function range(min: number, max: number | null): string {
  if (max === null) return `Over ${peso.format(min)}`;
  if (min === 0) return `Not over ${peso.format(max)}`;
  return `Over ${peso.format(min)} – ${peso.format(max)}`;
}

/**
 * A band while it is being edited. Kept as STRINGS, not numbers: a half-typed "25000" must not
 * be re-rendered as 25000 mid-keystroke, and an emptied field must stay empty rather than
 * snapping to 0. The rate is edited as a percentage — nobody publishing a BIR table thinks
 * in 0.15 — and converted on save.
 */
interface DraftBand {
  min: string;
  base: string;
  ratePct: string;
}

function toDraft(bands: TaxBand[]): DraftBand[] {
  return bands.map((b) => ({
    min: String(b.min_annual),
    base: String(b.base_tax),
    // 0.15 → "15", 0.205 → "20.5". Trailing zeros dropped so the field reads like the schedule.
    ratePct: String(Number((b.rate_on_excess * 100).toFixed(4))),
  }));
}

function num(v: string): number {
  const n = Number(String(v).replace(/,/g, "").trim());
  return Number.isFinite(n) ? n : NaN;
}

/**
 * The base tax a band SHOULD carry: everything the bands below it already add up to. This is
 * the same arithmetic SaveTaxTableRequest enforces on the server — mirrored here so the figure
 * is checkable while it is being typed, not only after a rejected save.
 */
function expectedBase(rows: DraftBand[], i: number): number | null {
  if (i === 0) return 0;
  const prev = rows[i - 1];
  const prevBase = num(prev.base);
  const prevRate = num(prev.ratePct) / 100;
  const width = num(rows[i].min) - num(prev.min);
  if (![prevBase, prevRate, width].every(Number.isFinite) || width <= 0) return null;
  return Math.round((prevBase + prevRate * width) * 100) / 100;
}

/** Tax on an annual taxable income under a draft table — mirrors TaxService::annualTax(). */
function taxUnder(rows: DraftBand[], income: number): number | null {
  if (!Number.isFinite(income) || income <= 0) return 0;
  let band: DraftBand | null = null;
  for (const row of rows) {
    const min = num(row.min);
    if (!Number.isFinite(min)) return null;
    if (income > min) band = row;
  }
  if (!band) return 0;
  const base = num(band.base);
  const rate = num(band.ratePct) / 100;
  if (!Number.isFinite(base) || !Number.isFinite(rate)) return null;
  return Math.round((base + rate * (income - num(band.min))) * 100) / 100;
}

/** Every problem with the draft, keyed by `${rowIndex}.${field}`, plus table-wide ones. */
function validate(rows: DraftBand[]): { fields: Record<string, string>; table: string[] } {
  const fields: Record<string, string> = {};
  const table: string[] = [];

  if (rows.length < 2) table.push("A tax table needs at least two bands — one exempt, one taxed.");

  rows.forEach((row, i) => {
    const min = num(row.min);
    const base = num(row.base);
    const rate = num(row.ratePct);

    if (!Number.isFinite(min) || min < 0) fields[`${i}.min`] = "Enter an amount.";
    if (!Number.isFinite(base) || base < 0) fields[`${i}.base`] = "Enter an amount.";
    if (!Number.isFinite(rate) || rate < 0 || rate > 100) fields[`${i}.ratePct`] = "0–100.";

    if (i === 0) {
      if (Number.isFinite(min) && min !== 0) {
        fields["0.min"] = "The first band must start at 0.";
      }
      if (Number.isFinite(base) && base !== 0) {
        fields["0.base"] = "Must be 0 — nothing is owed below the first band.";
      }
      return;
    }

    const prevMin = num(rows[i - 1].min);
    if (Number.isFinite(min) && Number.isFinite(prevMin) && min <= prevMin) {
      fields[`${i}.min`] = "Must be above the band before it.";
      return;
    }

    const should = expectedBase(rows, i);
    if (should !== null && Number.isFinite(base) && Math.abs(should - base) > 0.01) {
      fields[`${i}.base`] = `Should be ${plain.format(should)}.`;
    }
  });

  return { fields, table };
}

/** The withholding tax table — readable, and editable when a rate change is published. */
export function TaxTableDialog({
  open,
  onOpenChange,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const [asOf, setAsOf] = useState(manilaToday());
  const [draft, setDraft] = useState<DraftBand[] | null>(null);
  const [note, setNote] = useState("");
  const { data, isLoading, isError } = useTaxTable(open ? asOf : "");

  function close(next: boolean) {
    if (!next) {
      setDraft(null);
      setNote("");
      setAsOf(manilaToday());
    }
    onOpenChange(next);
  }

  return (
    <Dialog open={open} onOpenChange={close}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-[720px]">
        <DialogHeader>
          <DialogTitle>
            {draft ? "Publish a withholding tax table" : "Withholding tax table"}
          </DialogTitle>
          <DialogDescription>
            {draft
              ? "The BIR schedule, as published. Payroll withholds against these bands from today onwards."
              : "The annual bands payroll withholds against, from the BIR schedule."}
          </DialogDescription>
        </DialogHeader>

        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading the table…</p>
        ) : isError || !data ? (
          <p className="text-sm text-destructive">Could not load the tax table.</p>
        ) : draft ? (
          <TaxTableEditor
            data={data}
            rows={draft}
            setRows={setDraft}
            note={note}
            setNote={setNote}
            onDone={() => {
              setDraft(null);
              setNote("");
            }}
          />
        ) : (
          <TaxTableView
            data={data}
            asOf={asOf}
            setAsOf={setAsOf}
            onEdit={() => {
              setDraft(toDraft(data.bands));
              setNote(data.has_version_today ? (data.note ?? "") : "");
            }}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}

// ---- Read mode ----

function TaxTableView({
  data,
  asOf,
  setAsOf,
  onEdit,
}: {
  data: TaxTable;
  asOf: string;
  setAsOf: (v: string) => void;
  onEdit: () => void;
}) {
  const deleteMut = useDeleteTaxVersion();
  // Editing always publishes for TODAY, so it may only start from today's table. Prefilling
  // from a historical one would silently republish an old schedule as the current one.
  const viewingToday = asOf === data.today;
  const todaysVersion = data.versions.find((v) => v.effective_from === data.today);

  async function removeToday() {
    if (!todaysVersion) return;
    try {
      await deleteMut.mutateAsync(todaysVersion.id);
      toast.success("Today's table removed — the previous one applies again");
    } catch {
      toast.error("Could not remove it. The last remaining table cannot be deleted.");
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <Label htmlFor="tax-as-of" className="shrink-0">
            In force on
          </Label>
          <DatePicker id="tax-as-of" value={asOf} onChange={setAsOf} className="w-44" />
        </div>
        <Button variant="outline" size="sm" onClick={onEdit} disabled={!viewingToday}>
          <Pencil />
          Edit table
        </Button>
      </div>

      {!viewingToday && (
        <p className="text-xs text-muted-foreground">
          Showing a past table. Switch back to today&rsquo;s date to publish a change — a new
          table always takes effect from today.
        </p>
      )}

      {data.bands.length === 0 ? (
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
                        : `${Number((b.rate_on_excess * 100).toFixed(4))}%`}
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

          {viewingToday && todaysVersion && (
            <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border p-3">
              <p className="text-xs text-muted-foreground">
                This table was published today. Removing it puts the previous one back in force.
              </p>
              <Button
                variant="ghost"
                size="sm"
                onClick={removeToday}
                disabled={deleteMut.isPending}
              >
                <Trash2 />
                {deleteMut.isPending ? "Removing…" : "Remove today's table"}
              </Button>
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
  );
}

// ---- Edit mode ----

function TaxTableEditor({
  data,
  rows,
  setRows,
  note,
  setNote,
  onDone,
}: {
  data: TaxTable;
  rows: DraftBand[];
  setRows: (rows: DraftBand[]) => void;
  note: string;
  setNote: (v: string) => void;
  onDone: () => void;
}) {
  const saveMut = useSaveTaxTable();
  // A salary to see the change against. Nothing is priced from it — it exists so the table is
  // read as pesos before it is published, not as six rows of arithmetic.
  const [sample, setSample] = useState("500000");

  const { fields, table } = useMemo(() => validate(rows), [rows]);
  const valid = Object.keys(fields).length === 0 && table.length === 0;

  const sampleIncome = num(sample);
  const before = useMemo(
    () => taxUnder(toDraft(data.bands), sampleIncome),
    [data.bands, sampleIncome],
  );
  const after = useMemo(() => (valid ? taxUnder(rows, sampleIncome) : null), [rows, sampleIncome, valid]);

  function set(i: number, field: keyof DraftBand, value: string) {
    setRows(rows.map((row, j) => (j === i ? { ...row, [field]: value } : row)));
  }

  function addBand() {
    const last = rows[rows.length - 1];
    setRows([...rows, { min: String((num(last?.min) || 0) + 100000), base: "", ratePct: "" }]);
  }

  function removeBand(i: number) {
    setRows(rows.filter((_, j) => j !== i));
  }

  /** Fill every base tax from the floors and rates — the arithmetic, done for you. */
  function fillBases() {
    const filled: DraftBand[] = [];
    rows.forEach((row, i) => {
      filled.push(row);
      const should = expectedBase(filled, i);
      filled[i] = { ...row, base: should === null ? row.base : String(should) };
    });
    setRows(filled);
  }

  async function save() {
    // Sorted on the way out: the server requires ascending floors, and a band typed out of
    // order is a keying slip, not a different table.
    const bands = [...rows]
      .sort((a, b) => num(a.min) - num(b.min))
      .map((row) => ({
        min_annual: num(row.min),
        base_tax: num(row.base),
        rate_on_excess: Math.round((num(row.ratePct) / 100) * 10000) / 10000,
      }));

    try {
      await saveMut.mutateAsync({ bands, note });
      toast.success(`Tax table published — in force from ${data.today}`);
      onDone();
    } catch {
      toast.error("Could not publish the table. Check the figures and try again.");
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex items-start gap-2 rounded-lg border border-warning bg-warning-subtle p-3">
        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden />
        <p className="text-xs text-foreground">
          This changes what <strong>every</strong> employee has withheld. It takes effect{" "}
          <strong>{data.today}</strong> and applies to the whole current pay period — periods
          already run are untouched, and anything retroactive goes through a payslip correction.
          {data.has_version_today && (
            <> You already published a table today; saving replaces it.</>
          )}
        </p>
      </div>

      <div className="overflow-x-auto rounded-lg border border-border">
        <table className="w-full min-w-[560px] text-sm">
          <thead>
            <tr className="border-b border-border bg-muted/60 text-label font-bold uppercase tracking-wide text-muted-foreground">
              <th className="px-3 py-2 text-left">Income over</th>
              <th className="px-3 py-2 text-left">Base tax</th>
              <th className="px-3 py-2 text-left">Rate on excess</th>
              <th className="w-10 px-3 py-2" />
            </tr>
          </thead>
          <tbody>
            {rows.map((row, i) => (
              <tr key={i} className="border-b border-border align-top last:border-0">
                {i === 0 ? (
                  // The exempt tier. Its floor is 0 and its base tax is 0 by definition — income
                  // has to start somewhere and nothing is owed below the first band — so these are
                  // stated, not typed. Raising the exempt THRESHOLD means moving the floor of the
                  // band BELOW, which is row 2 and fully editable.
                  <td className="px-3 py-3 text-sm text-muted-foreground" colSpan={2}>
                    ₱0 upwards, nothing owed — the exempt tier
                  </td>
                ) : (
                  <>
                    <td className="px-3 py-2">
                      <Input
                        inputMode="decimal"
                        value={row.min}
                        aria-label={`Band ${i + 1} starts over`}
                        aria-invalid={!!fields[`${i}.min`]}
                        onChange={(e) => set(i, "min", e.target.value)}
                      />
                      {fields[`${i}.min`] && (
                        <p className="mt-1 text-xs text-destructive">{fields[`${i}.min`]}</p>
                      )}
                    </td>
                    <td className="px-3 py-2">
                      <Input
                        inputMode="decimal"
                        value={row.base}
                        aria-label={`Band ${i + 1} base tax`}
                        aria-invalid={!!fields[`${i}.base`]}
                        onChange={(e) => set(i, "base", e.target.value)}
                      />
                      {fields[`${i}.base`] && (
                        <p className="mt-1 text-xs text-destructive">{fields[`${i}.base`]}</p>
                      )}
                    </td>
                  </>
                )}
                <td className="px-3 py-2">
                  <div className="flex items-center gap-1.5">
                    <Input
                      inputMode="decimal"
                      value={row.ratePct}
                      aria-label={`Band ${i + 1} rate percent`}
                      aria-invalid={!!fields[`${i}.ratePct`]}
                      onChange={(e) => set(i, "ratePct", e.target.value)}
                    />
                    <span className="text-sm text-muted-foreground">%</span>
                  </div>
                  {fields[`${i}.ratePct`] && (
                    <p className="mt-1 text-xs text-destructive">{fields[`${i}.ratePct`]}</p>
                  )}
                </td>
                <td className="px-3 py-2">
                  {i > 0 && (
                    <Button
                      variant="ghost"
                      size="icon-sm"
                      onClick={() => removeBand(i)}
                      aria-label={`Remove band ${i + 1}`}
                    >
                      <Trash2 />
                    </Button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <Button variant="outline" size="sm" onClick={addBand}>
          <Plus />
          Add band
        </Button>
        <Button variant="ghost" size="sm" onClick={fillBases}>
          <Wand2 />
          Fill base tax from rates
        </Button>
        <p className="max-w-md text-xs text-muted-foreground">
          To change where tax starts, edit the <strong>second</strong> row&rsquo;s floor — that is
          the threshold. The first row is the exempt tier and is fixed at 0.
        </p>
      </div>

      {table.map((message) => (
        <p key={message} className="text-xs text-destructive">
          {message}
        </p>
      ))}

      <div className="space-y-1.5">
        <Label htmlFor="tax-note">Note</Label>
        <Input
          id="tax-note"
          placeholder="e.g. RA 10963 (TRAIN), rates from 1 January 2023"
          value={note}
          onChange={(e) => setNote(e.target.value)}
        />
        <p className="text-xs text-muted-foreground">
          Shown beside the table and kept in the audit trail. Record the issuance it came from.
        </p>
      </div>

      <div className="rounded-lg border border-border p-3">
        <div className="flex flex-wrap items-end gap-3">
          <div className="space-y-1.5">
            <Label htmlFor="tax-sample">Check it against an annual taxable income</Label>
            <Input
              id="tax-sample"
              inputMode="decimal"
              value={sample}
              onChange={(e) => setSample(e.target.value)}
              className="w-44"
            />
          </div>
          <p className="pb-2 text-sm text-foreground">
            {before === null || after === null ? (
              <span className="text-muted-foreground">
                Fix the figures above to see what this table charges.
              </span>
            ) : (
              <>
                Now <strong>{peso.format(before)}</strong> a year → after{" "}
                <strong>{peso.format(after)}</strong>
                {after !== before && (
                  <span className="text-muted-foreground">
                    {" "}
                    ({after < before ? "−" : "+"}
                    {peso.format(Math.abs(after - before))})
                  </span>
                )}
              </>
            )}
          </p>
        </div>
      </div>

      <div className="flex flex-wrap items-center justify-end gap-3">
        <Button variant="ghost" onClick={onDone} disabled={saveMut.isPending}>
          Cancel
        </Button>
        <Button onClick={save} disabled={!valid || saveMut.isPending}>
          {saveMut.isPending ? "Publishing…" : `Publish for ${data.today}`}
        </Button>
      </div>
    </div>
  );
}
