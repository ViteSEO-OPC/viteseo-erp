"use client";

import { useRef, useState } from "react";
import { AlertTriangle, Check, FileSpreadsheet, Upload } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { formatPeso } from "@/core/lib/format";
import { notify as toast } from "@/core/lib/notify";
import { useImportContributionSchedule } from "@/modules/hr/hooks/use-benefits";
import { Explainer } from "@/modules/hr/ui/explainer";
import { SimpleSelect } from "@/modules/hr/ui/select";
import type { ContributionBand, ScheduleImportResult } from "@/modules/hr/api/benefits";

/** The columns the system looks at — and it is only ever these five, out of a dozen in the file. */
const FIELDS = [
  { key: "range", label: "Salary range", hint: "Where each bracket starts", role: "saved" },
  { key: "msc", label: "Charged on (MSC)", hint: "What the rate is applied to — the TOTAL, not a part of it", role: "saved" },
  { key: "employer_add_on", label: "Employer EC", hint: "The employer-only amount, per band", role: "saved" },
  { key: "employee_total", label: "Employee pays", hint: "Not saved — checked against our own maths", role: "checked" },
  { key: "employer_total", label: "Employer pays", hint: "Not saved — checked against our own maths", role: "checked" },
] as const;

/** Chosen when a column simply is not in the file (a table with no EC column, say). */
const NOT_IN_FILE = "__none__";

/** A → Z, AA → AZ: enough for any published schedule. */
const COLUMN_LETTERS = [
  ...Array.from({ length: 26 }, (_, i) => String.fromCharCode(65 + i)),
  ...Array.from({ length: 26 }, (_, i) => `A${String.fromCharCode(65 + i)}`),
];

/** Load the SSS bracket table by uploading the agency's own spreadsheet. */
export function ScheduleUpload({
  current,
  imported,
  onImported,
}: {
  /** The bands already on this version — what stays in force if nothing is uploaded. */
  current: ContributionBand[];
  imported: ScheduleImportResult | null;
  onImported: (result: ScheduleImportResult) => void;
}) {
  const input = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [columns, setColumns] = useState<Record<string, string>>({});
  const importMut = useImportContributionSchedule();

  async function send(next: File, overrides: Record<string, string> = columns) {
    try {
      const result = await importMut.mutateAsync({ file: next, columns: overrides });
      onImported(result);
      if (result.bands.length === 0) {
        toast.error("Could not find the table in that file — point out the columns below.");
      } else {
        toast.success(`Read ${result.bands.length} salary brackets`);
      }
    } catch {
      toast.error("Could not read that file.");
    }
  }

  function choose(next: File | null) {
    if (!next) return;
    setFile(next);
    send(next);
  }

  // Re-reading with a corrected column is the recovery path when the headings are unusual.
  // "Not in this file" sends an empty override, which the importer reads as "do not use a column
  // for this" rather than falling back to its own detection.
  function repoint(field: string, column: string) {
    const next = { ...columns, [field]: column === NOT_IN_FILE ? "" : column };
    setColumns(next);
    if (file) send(file, next);
  }

  return (
    // Its own `group/explain` as well as the Section's, so the text still reveals if this is
    // ever used somewhere that is not a Section.
    <div className="group/explain space-y-3">
      <Explainer>
        <p className="text-caption text-muted-foreground">
          Upload the schedule as the agency publishes it — an Excel file (.xlsx) or a CSV. The
          system finds the table by its headings, so the title lines and the extra columns can
          stay exactly as they are. Nothing is saved until you press Save below.
        </p>
      </Explainer>

      <input
        ref={input}
        type="file"
        accept=".xlsx,.csv,text/csv"
        className="hidden"
        onChange={(e) => choose(e.target.files?.[0] ?? null)}
      />
      <div className="flex flex-wrap items-center gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => input.current?.click()}
          disabled={importMut.isPending}
        >
          <Upload />
          {importMut.isPending ? "Reading…" : "Choose a file"}
        </Button>
        {file && (
          <span className="inline-flex items-center gap-1.5 text-caption text-muted-foreground">
            <FileSpreadsheet className="size-3.5" />
            {file.name}
          </span>
        )}
      </div>

      {!imported && current.length > 0 && (
        <p className="text-caption text-muted-foreground">
          This schedule already has <strong>{current.length}</strong> brackets, up to{" "}
          {formatPeso(current[current.length - 1].msc)}. Uploading a file replaces all of them.
        </p>
      )}

      {imported && <ImportSummary result={imported} columns={columns} onRepoint={repoint} />}
    </div>
  );
}

function ImportSummary({
  result,
  columns,
  onRepoint,
}: {
  result: ScheduleImportResult;
  columns: Record<string, string>;
  onRepoint: (field: string, column: string) => void;
}) {
  const bands = result.bands;
  const top = bands[bands.length - 1];

  return (
    <div className="space-y-3">
      {result.title && (
        <p className="text-caption text-muted-foreground">
          Read from: <span className="font-medium text-foreground">{result.title}</span>
          {result.header_row && ` · headings on row ${result.header_row}`}
        </p>
      )}

      {result.warnings.map((warning) => (
        <p
          key={warning}
          className="flex items-start gap-1.5 rounded-lg border border-warning/40 bg-warning-subtle px-3 py-2 text-caption text-foreground"
        >
          <AlertTriangle className="mt-0.5 size-3.5 shrink-0 text-warning" />
          {warning}
        </p>
      ))}

      {/*
       * The guidance the file may need: which column is which. Every column the system looks at
       * is listed — including the two it only checks against — so nothing is read invisibly, and
       * any of them can be re-pointed without a code change.
       */}
      <div className="space-y-3">
        <Explainer>
          <p className="text-caption text-muted-foreground">
            These are the only columns the system reads. The first three are saved as the bracket
            table; the last two are not saved at all — they are the amounts the agency printed,
            read back to check our own maths. Every other column in your file is a component of
            one of these and is ignored.
          </p>
        </Explainer>
        <div className="grid gap-3 sm:grid-cols-3">
          {FIELDS.map((field) => (
            <div key={field.key}>
              <Label className="mb-1.5 block text-label">
                {field.label}
                {field.role === "checked" && (
                  <span className="ml-1.5 font-normal normal-case text-muted-foreground">
                    (check only)
                  </span>
                )}
              </Label>
              <SimpleSelect
                value={columns[field.key] ?? result.columns[field.key] ?? NOT_IN_FILE}
                onValueChange={(v) => onRepoint(field.key, v)}
                options={[
                  { value: NOT_IN_FILE, label: "Not in this file" },
                  ...COLUMN_LETTERS.map((c) => ({ value: c, label: `Column ${c}` })),
                ]}
              />
              <Explainer>
                <p className="text-caption text-muted-foreground">{field.hint}</p>
              </Explainer>
            </div>
          ))}
        </div>
      </div>

      {bands.length > 0 && (
        <>
          {/* Check a few rows read correctly before trusting the other fifty. */}
          <div className="overflow-x-auto rounded-lg border border-border">
            <table className="w-full text-caption">
              <thead className="bg-muted/60 text-muted-foreground">
                <tr>
                  <th className="px-3 py-1.5 text-left">As printed</th>
                  <th className="px-3 py-1.5 text-right">Salary from</th>
                  <th className="px-3 py-1.5 text-right">Charged on</th>
                  <th className="px-3 py-1.5 text-right">Employer EC</th>
                </tr>
              </thead>
              <tbody>
                {bands.slice(0, 3).map((band) => (
                  <tr key={band.min_salary} className="border-t border-border">
                    <td className="px-3 py-1.5 text-muted-foreground">{band.range}</td>
                    <td className="px-3 py-1.5 text-right">{formatPeso(band.min_salary)}</td>
                    <td className="px-3 py-1.5 text-right font-medium">{formatPeso(band.msc)}</td>
                    <td className="px-3 py-1.5 text-right">
                      {band.employer_add_on != null ? formatPeso(band.employer_add_on) : "—"}
                    </td>
                  </tr>
                ))}
                {bands.length > 4 && (
                  <tr className="border-t border-border text-muted-foreground">
                    <td className="px-3 py-1.5" colSpan={4}>
                      …and {bands.length - 4} more
                    </td>
                  </tr>
                )}
                {bands.length > 3 && (
                  <tr className="border-t border-border">
                    <td className="px-3 py-1.5 text-muted-foreground">{top.range}</td>
                    <td className="px-3 py-1.5 text-right">{formatPeso(top.min_salary)}</td>
                    <td className="px-3 py-1.5 text-right font-medium">{formatPeso(top.msc)}</td>
                    <td className="px-3 py-1.5 text-right">
                      {top.employer_add_on != null ? formatPeso(top.employer_add_on) : "—"}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          <p className="text-caption text-muted-foreground">
            <strong>{bands.length}</strong> brackets · the lowest starts at ₱0 so every salary
            falls into one · anyone above {formatPeso(top.min_salary)} is charged on{" "}
            {formatPeso(top.msc)}, which makes that the ceiling.
          </p>

          {/*
           * The strongest check available: does our own arithmetic reproduce the peso amounts
           * the agency printed? If it does, there is nothing to argue about.
           */}
          {result.suggested.amounts_match ? (
            <p className="flex items-start gap-1.5 rounded-lg border border-success/40 bg-success-subtle px-3 py-2 text-caption text-foreground">
              <Check className="mt-0.5 size-3.5 shrink-0 text-success" />
              Checked against the amounts printed in the file: every bracket matches what this
              system will charge
              {result.suggested.employee_rate != null &&
                ` at ${(result.suggested.employee_rate * 100).toFixed(2)}% employee / ${((result.suggested.employer_rate ?? 0) * 100).toFixed(2)}% employer`}
              . The rates above have been filled in for you.
            </p>
          ) : (
            <p className="flex items-start gap-1.5 rounded-lg border border-warning/40 bg-warning-subtle px-3 py-2 text-caption text-foreground">
              <AlertTriangle className="mt-0.5 size-3.5 shrink-0 text-warning" />
              The file did not include amounts we could check against, or they did not match.
              Compare the worked example below with the circular before saving.
            </p>
          )}
        </>
      )}
    </div>
  );
}
