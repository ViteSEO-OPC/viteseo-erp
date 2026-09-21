"use client";

import { useMemo, useState } from "react";

import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { formatPeso } from "@/core/lib/format";
import { useDebouncedValue } from "@/core/use-debounced-value";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { useInternAllowancePreview } from "@/modules/hr/hooks/use-intern-allowance";
import { internAllowanceUnit } from "@/modules/hr/lib/intern-allowance-options";
import type {
  InternAllowanceSettings,
  InternPreviewSample,
} from "@/modules/hr/types/intern-allowance";

const FREQUENCIES = [
  { value: "semi_monthly", label: "Semi-monthly" },
  { value: "monthly", label: "Monthly" },
  { value: "bi_weekly", label: "Bi-weekly" },
  { value: "weekly", label: "Weekly" },
];

const METHOD_NAMES: Record<string, string> = {
  per_day: "Per day",
  per_hour: "Per hour",
  fixed_monthly: "Fixed monthly",
};

/** The counters the example intern is described by. */
const COUNTERS = [
  ["scheduled_days", "Scheduled days"],
  ["absent_days", "Absent"],
  ["half_days", "Half-day"],
  ["leave_days", "Leave"],
  ["regular_holidays_off", "Regular holidays (not worked)"],
  ["rest_days_worked", "Rest days worked"],
] as const;

type Counter = (typeof COUNTERS)[number][0];

function whole(v: string): number {
  const n = Math.floor(Number(v));
  return Number.isFinite(n) && n > 0 ? n : 0;
}

/**
 * Company → Intern Allowance → Calculation Preview. An example intern priced by the SERVER, under
 * the settings as they stand on the page — the same pricing code a payroll run uses, so what this
 * shows is what a payslip would pay.
 */
export function InternCalculationPreview({ settingsJson }: { settingsJson: string }) {
  const [counts, setCounts] = useState<Record<Counter, string>>({
    scheduled_days: "11",
    absent_days: "1",
    half_days: "1",
    leave_days: "0",
    regular_holidays_off: "0",
    rest_days_worked: "0",
  });
  const [frequency, setFrequency] = useState("semi_monthly");
  const [lateMinutes, setLateMinutes] = useState("0");
  const [hoursRendered, setHoursRendered] = useState("");
  const [overtimeMinutes, setOvertimeMinutes] = useState("0");
  const [override, setOverride] = useState("");

  const sample = useMemo<InternPreviewSample>(() => {
    const s: InternPreviewSample = {
      frequency,
      scheduled_days: Math.max(1, Math.min(31, whole(counts.scheduled_days) || 1)),
      absent_days: whole(counts.absent_days),
      half_days: whole(counts.half_days),
      leave_days: whole(counts.leave_days),
      regular_holidays_off: whole(counts.regular_holidays_off),
      rest_days_worked: whole(counts.rest_days_worked),
      overtime_minutes: whole(overtimeMinutes),
    };
    if (whole(lateMinutes) > 0) s.late_minutes = [whole(lateMinutes)];
    if (hoursRendered.trim() !== "" && Number.isFinite(Number(hoursRendered))) {
      s.hours_rendered = [Math.max(0, Math.min(24, Number(hoursRendered)))];
    }
    if (override.trim() !== "" && Number(override) >= 0) s.amount_override = Number(override);
    return s;
  }, [counts, frequency, lateMinutes, hoursRendered, overtimeMinutes, override]);

  const sampleJson = useDebouncedValue(JSON.stringify(sample), 400);
  const settings = settingsJson ? (JSON.parse(settingsJson) as InternAllowanceSettings) : null;
  const { data: preview, isFetching, isError } = useInternAllowancePreview(
    settings,
    JSON.parse(sampleJson) as InternPreviewSample,
  );

  const used =
    whole(counts.absent_days) +
    whole(counts.half_days) +
    whole(counts.leave_days) +
    whole(counts.regular_holidays_off);
  const present = Math.max(0, sample.scheduled_days - used);
  const tooMany = used > sample.scheduled_days;
  const unit = internAllowanceUnit(preview?.rates.method);

  return (
    <section className="space-y-5 rounded-card border border-border bg-card p-6">
      <div className="space-y-1">
        <h2 className="text-h3 font-bold text-foreground">6. Calculation Preview</h2>
        <p className="text-sm text-muted-foreground">
          An example intern priced under the rules above, before you save — at the company&rsquo;s
          premium rates in force today.
        </p>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {/* The example intern */}
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
          <div className="col-span-2 space-y-1.5 sm:col-span-3">
            <Label htmlFor="preview-frequency">Pay frequency</Label>
            <SimpleSelect id="preview-frequency" value={frequency} onValueChange={setFrequency} options={FREQUENCIES} />
          </div>
          {COUNTERS.map(([key, label]) => (
            <div key={key} className="space-y-1.5">
              <Label htmlFor={`preview-${key}`}>{label}</Label>
              <Input
                id={`preview-${key}`}
                type="number"
                min="0"
                max="31"
                value={counts[key]}
                onChange={(e) => setCounts((c) => ({ ...c, [key]: e.target.value }))}
              />
            </div>
          ))}
          <div className="space-y-1.5">
            <Label htmlFor="preview-late">Minutes late (one day)</Label>
            <Input id="preview-late" type="number" min="0" value={lateMinutes} onChange={(e) => setLateMinutes(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="preview-hours">Hours rendered (one day)</Label>
            <Input id="preview-hours" type="number" min="0" max="24" step="0.5" placeholder="Full day" value={hoursRendered} onChange={(e) => setHoursRendered(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="preview-ot">Overtime minutes</Label>
            <Input id="preview-ot" type="number" min="0" value={overtimeMinutes} onChange={(e) => setOvertimeMinutes(e.target.value)} />
          </div>
          <div className="col-span-2 space-y-1.5 sm:col-span-3">
            <Label htmlFor="preview-override">Intern&rsquo;s own allowance (optional)</Label>
            <Input id="preview-override" type="number" min="0" step="0.01" placeholder="Company default" value={override} onChange={(e) => setOverride(e.target.value)} />
          </div>
          {tooMany && (
            <p className="col-span-2 text-xs text-destructive sm:col-span-3">
              The days listed add up to more than the scheduled days.
            </p>
          )}
        </div>

        {/* What it pays */}
        <div className="space-y-4">
          {!settings ? (
            <p className="text-sm text-muted-foreground">Complete the required fields above to see the preview.</p>
          ) : isError ? (
            <p className="text-sm text-destructive">The preview could not be calculated for these settings.</p>
          ) : !preview ? (
            <p className="text-sm text-muted-foreground">Calculating…</p>
          ) : (
            <div className={isFetching ? "opacity-60 transition-opacity" : "transition-opacity"}>
              <div className="overflow-hidden rounded-lg border border-border">
                <table className="w-full text-sm">
                  <thead className="bg-muted/50 text-left">
                    <tr>
                      <th className="px-3 py-2 font-semibold">Item</th>
                      <th className="px-3 py-2 text-right font-semibold">Value</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    <PreviewRow label="Calculation method" value={METHOD_NAMES[preview.rates.method] ?? preview.rates.method} />
                    <PreviewRow
                      label={`Allowance (${preview.rates.amount_source === "override" ? "intern's own" : "company default"})`}
                      value={`${formatPeso(preview.rates.amount)} ${unit}`}
                    />
                    <PreviewRow label="Scheduled days" value={String(sample.scheduled_days)} />
                    <PreviewRow label="Present" value={`${present} days`} />
                    {preview.earnings.map((line) => (
                      <PreviewRow key={line.key} label={line.name} note={line.note} value={formatPeso(line.amount)} />
                    ))}
                    {preview.deductions.map((row) => (
                      <PreviewRow key={row.key} label={`${row.name}${row.count ? ` (${row.count})` : ""}`} value={`−${formatPeso(row.amount)}`} />
                    ))}
                    <PreviewRow label="Gross allowance" value={formatPeso(preview.totals.allowance + preview.totals.premiums)} />
                    <PreviewRow label="Deductions" value={formatPeso(preview.totals.deductions)} />
                    <tr className="bg-muted/40">
                      <td className="px-3 py-2 font-bold">Net Allowance</td>
                      <td className="px-3 py-2 text-right font-mono font-bold">{formatPeso(preview.totals.net_allowance)}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p className="mt-2 text-xs text-muted-foreground">
                Formula: Net Allowance = Earned Allowance − Applicable Deductions. No government
                contributions, withholding tax or loan deductions are taken from an intern&rsquo;s allowance.
              </p>
              {preview.flags.length > 0 && (
                <ul className="mt-2 space-y-1 text-xs text-muted-foreground">
                  {preview.flags.map((flag, i) => (
                    <li key={`${flag.date}-${flag.code}-${i}`}>
                      {flag.date}: {flag.detail ?? flag.code.replaceAll("_", " ")}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}
        </div>
      </div>
    </section>
  );
}

function PreviewRow({ label, value, note }: { label: string; value: string; note?: string | null }) {
  return (
    <tr>
      <td className="px-3 py-2">
        {label}
        {note && <span className="ml-1 text-xs text-muted-foreground">({note})</span>}
      </td>
      <td className="px-3 py-2 text-right font-mono">{value}</td>
    </tr>
  );
}
