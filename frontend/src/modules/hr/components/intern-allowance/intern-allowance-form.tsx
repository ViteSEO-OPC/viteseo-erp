"use client";

import { useState } from "react";
import { Controller, useForm, useWatch, type Control, type Path, type UseFormRegister } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AxiosError } from "axios";
import { AlertTriangle } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { useDebouncedValue } from "@/core/use-debounced-value";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { MultiCombobox } from "@/modules/hr/ui/multi-combobox";
import { manilaToday } from "@/modules/hr/lib/manila-date";
import { useLeaveTypes } from "@/modules/hr/hooks/use-leave-config";
import { useSaveInternAllowanceSettings } from "@/modules/hr/hooks/use-intern-allowance";
import { InternCalculationPreview } from "@/modules/hr/components/intern-allowance/calculation-preview";
import {
  FIELD_LABELS,
  PREMIUM_RULES,
  isHoursBased,
  optionsFor,
  premiumOn,
  type PremiumPrefix,
} from "@/modules/hr/lib/intern-allowance-options";
import type {
  InternAllowanceMethod,
  InternAllowanceOverview,
  InternAllowanceSettings,
} from "@/modules/hr/types/intern-allowance";

const text = z.string();

/** Every setting as the form holds it: choices and figures as strings, one boolean, one list. */
const shape = z.object({
  method: text,
  required_hours_per_day: text,
  daily_amount: text,
  per_day_proration: text,
  hourly_amount: text,
  max_paid_hours_per_day: text,
  monthly_amount: text,
  monthly_proration: text,
  late_rule: text,
  late_fixed_amount: text,
  late_partial_after_minutes: text,
  partial_day_pricing: text,
  undertime_rule: text,
  undertime_fixed_amount: text,
  early_out_rule: text,
  absence_rule: text,
  half_day_rule: text,
  late_penalty_rule: text,
  premium_day_basis: text,
  rest_day_worked: text,
  rest_day_premium_source: text,
  rest_day_premium_pct: text,
  regular_holiday_not_worked: text,
  regular_holiday_worked: text,
  regular_holiday_rest_day_not_worked: text,
  regular_holiday_premium_source: text,
  regular_holiday_premium_pct: text,
  special_holiday_not_worked: text,
  special_holiday_worked: text,
  special_holiday_rest_day_not_worked: text,
  special_holiday_premium_source: text,
  special_holiday_premium_pct: text,
  special_working_day: text,
  holiday_forfeit_after_absence: z.boolean(),
  suspension_not_worked: text,
  suspension_worked: text,
  suspension_premium_source: text,
  suspension_premium_pct: text,
  overtime_rule: text,
  overtime_premium_source: text,
  overtime_premium_pct: text,
  night_diff_rule: text,
  night_diff_pct: text,
  min_attendance_pct: text,
  below_min_attendance: text,
  leave_pay_rule: text,
  leave_types_allowed: text,
  leave_type_ids: z.array(z.string()),
  distribution_timing: text,
  effective_from: text,
});

type FormValues = z.infer<typeof shape>;

/** Mirrors UpdateInternAllowanceSettingsRequest, so the form catches what the API would refuse. */
const schema = shape.superRefine((v, ctx) => {
  const need = (
    when: boolean,
    key: keyof FormValues,
    message: string,
    ok: (n: number) => boolean = (n) => n >= 0,
  ) => {
    if (!when) return;
    const raw = String(v[key] ?? "").trim();
    const n = Number(raw);
    if (raw === "" || !Number.isFinite(n) || !ok(n)) {
      ctx.addIssue({ code: "custom", path: [key], message });
    }
  };

  need(true, "required_hours_per_day", "Enter 0.5 to 24 hours", (n) => n >= 0.5 && n <= 24);
  need(v.method === "per_day", "daily_amount", "Enter the daily allowance");
  need(v.method === "per_hour", "hourly_amount", "Enter the hourly allowance");
  need(v.method === "per_hour", "max_paid_hours_per_day", "Enter 0.5 to 24 hours", (n) => n >= 0.5 && n <= 24);
  need(v.method === "fixed_monthly", "monthly_amount", "Enter the monthly allowance");
  need(v.late_rule === "fixed_amount", "late_fixed_amount", "Enter an amount above 0", (n) => n > 0);
  need(
    v.late_rule === "partial_after_minutes",
    "late_partial_after_minutes",
    "Enter 1 to 480 minutes",
    (n) => Number.isInteger(n) && n >= 1 && n <= 480,
  );
  need(v.undertime_rule === "fixed_amount", "undertime_fixed_amount", "Enter an amount above 0", (n) => n > 0);
  for (const prefix of Object.keys(PREMIUM_RULES) as PremiumPrefix[]) {
    need(
      premiumOn(v, prefix) && v[`${prefix}_premium_source` as keyof FormValues] === "custom",
      `${prefix}_premium_pct` as keyof FormValues,
      "Enter 100% to 500%",
      (n) => n >= 100 && n <= 500,
    );
  }
  need(v.night_diff_rule === "custom", "night_diff_pct", "Enter 0% to 100%", (n) => n >= 0 && n <= 100);
  need(true, "min_attendance_pct", "Enter a whole 0 to 100", (n) => Number.isInteger(n) && n >= 0 && n <= 100);
  if (v.leave_types_allowed === "selected" && v.leave_type_ids.length === 0) {
    ctx.addIssue({ code: "custom", path: ["leave_type_ids"], message: "Pick at least one leave type" });
  }
});

const NUMBER_KEYS = [
  "required_hours_per_day", "daily_amount", "hourly_amount", "max_paid_hours_per_day",
  "monthly_amount", "late_fixed_amount", "late_partial_after_minutes", "undertime_fixed_amount",
  "rest_day_premium_pct", "regular_holiday_premium_pct", "special_holiday_premium_pct",
  "suspension_premium_pct", "overtime_premium_pct", "night_diff_pct", "min_attendance_pct",
] as const;

function toForm(settings: InternAllowanceSettings, effectiveFrom: string): FormValues {
  const values = { ...settings } as unknown as Record<string, unknown>;
  for (const key of NUMBER_KEYS) {
    const n = settings[key];
    values[key] = n === null || n === undefined ? "" : String(n);
  }
  values.leave_type_ids = settings.leave_type_ids.map(String);
  values.effective_from = effectiveFrom;

  return values as FormValues;
}

function toSettings(v: FormValues): InternAllowanceSettings {
  const settings = { ...v } as unknown as Record<string, unknown>;
  for (const key of NUMBER_KEYS) {
    const raw = v[key].trim();
    settings[key] = raw === "" ? null : Number(raw);
  }
  // A premium rate is only sent for a premium that is actually paid, so a custom rate left behind
  // on a switched-off premium cannot trip the server's "rate required" check.
  for (const prefix of Object.keys(PREMIUM_RULES) as PremiumPrefix[]) {
    if (!premiumOn(v, prefix)) {
      settings[`${prefix}_premium_source`] = "company_rate";
      settings[`${prefix}_premium_pct`] = null;
    }
  }
  settings.leave_type_ids = v.leave_type_ids.map(Number);
  delete settings.effective_from;

  return settings as unknown as InternAllowanceSettings;
}

// --- layout pieces ------------------------------------------------------------------------------

function Section({
  number,
  title,
  description,
  children,
}: {
  number: number;
  title: string;
  description: string;
  children: React.ReactNode;
}) {
  return (
    <section className="space-y-5 rounded-card border border-border bg-card p-6">
      <div className="space-y-1">
        <h2 className="text-h3 font-bold text-foreground">
          {number}. {title}
        </h2>
        <p className="text-sm text-muted-foreground">{description}</p>
      </div>
      {children}
    </section>
  );
}

function Grid({ children }: { children: React.ReactNode }) {
  return <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">{children}</div>;
}

function SubHeading({ children }: { children: React.ReactNode }) {
  return (
    <p className="text-label font-semibold uppercase tracking-wide text-muted-foreground">{children}</p>
  );
}

function FieldError({ message }: { message?: string }) {
  return message ? <p className="text-xs text-destructive">{message}</p> : null;
}

function Choice({
  control,
  name,
  label,
  options,
  disabled,
  hint,
}: {
  control: Control<FormValues>;
  name: Path<FormValues>;
  label: string;
  options: { value: string; label: string }[];
  disabled?: boolean;
  hint?: string;
}) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={name}>{label}</Label>
      <Controller
        control={control}
        name={name}
        render={({ field }) => (
          <SimpleSelect
            id={name}
            value={String(field.value)}
            onValueChange={field.onChange}
            options={options}
            disabled={disabled}
          />
        )}
      />
      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  );
}

function Figure({
  register,
  name,
  label,
  error,
  step = "0.01",
  placeholder,
  hint,
}: {
  register: UseFormRegister<FormValues>;
  name: Path<FormValues>;
  label: string;
  error?: string;
  step?: string;
  placeholder?: string;
  hint?: string;
}) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={name}>{label}</Label>
      <Input
        id={name}
        type="number"
        min="0"
        step={step}
        placeholder={placeholder}
        aria-invalid={!!error}
        {...register(name)}
      />
      {hint && !error && <p className="text-xs text-muted-foreground">{hint}</p>}
      <FieldError message={error} />
    </div>
  );
}

// --- the form -----------------------------------------------------------------------------------

/** Company → Intern Allowance: every choice on how an intern's allowance is earned, docked and paid. */
export function InternAllowanceForm({ overview }: { overview: InternAllowanceOverview }) {
  const saveMut = useSaveInternAllowanceSettings();
  const { data: leaveTypes } = useLeaveTypes();
  const [overrideQuestion, setOverrideQuestion] = useState<{
    message: string;
    values: FormValues;
  } | null>(null);

  const {
    control,
    register,
    reset,
    setError,
    handleSubmit,
    formState: { errors, isSubmitting, isDirty },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: toForm(overview.settings, manilaToday()),
  });

  const opts = (key: string) => optionsFor(key, overview.options);
  const values = useWatch({ control }) as FormValues;
  const hoursBased = isHoursBased(values);
  const method = values.method as InternAllowanceMethod;

  // The preview asks the server, so it is sent only what the server would accept, and only once
  // typing has paused. Serialised first: the watched values are a new object on every render.
  const parsed = schema.safeParse(values);
  const settingsJson = useDebouncedValue(parsed.success ? JSON.stringify(toSettings(parsed.data)) : "", 400);

  async function save(v: FormValues, confirmOverrides = false) {
    try {
      const saved = await saveMut.mutateAsync({
        ...toSettings(v),
        effective_from: v.effective_from || null,
        ...(confirmOverrides ? { confirm_override_reinterpretation: true } : {}),
      });

      const version = saved.versions.find((x) => x.effective_from === saved.saved_effective_from);
      reset(toForm(version?.settings ?? saved.settings, v.effective_from));

      const scheduled = !!saved.saved_effective_from && saved.saved_effective_from > manilaToday();
      toast.success(
        scheduled ? `Saved — takes effect ${saved.saved_effective_from}` : "Intern allowance rules saved",
        saved.corrected_fields.length > 0
          ? `Adjusted so no minute is charged twice: ${saved.corrected_fields
              .map((f) => FIELD_LABELS[f] ?? f)
              .join(", ")}.`
          : undefined,
      );
    } catch (err) {
      if (err instanceof AxiosError && err.response?.status === 422) {
        const serverErrors: Record<string, string[]> = err.response.data?.errors ?? {};
        const keys = Object.keys(serverErrors);

        // Changing the unit while interns have their own allowance is a question, not a failure.
        if (!confirmOverrides && keys.length === 1 && keys[0] === "method") {
          setOverrideQuestion({ message: serverErrors.method[0], values: v });
          return;
        }

        for (const [key, messages] of Object.entries(serverErrors)) {
          setError(key as Path<FormValues>, { message: messages[0] });
        }
        toast.error(Object.values(serverErrors)[0]?.[0] ?? "Could not save the rules.");
        return;
      }
      toast.error("Could not save the rules. Please try again.");
    }
  }

  const minuteRuleHint = hoursBased
    ? "Paid by the hours rendered — the minutes missed are already unpaid."
    : undefined;

  return (
    <form onSubmit={handleSubmit((v) => save(v))} className="space-y-8">
      {/* 1 — Allowance calculation */}
      <Section
        number={1}
        title="Allowance Calculation"
        description="The company default. HR can set an intern's own allowance on their record, read in this same unit."
      >
        <Grid>
          <Choice control={control} name="method" label="Calculation method" options={opts("method")} />
          <Figure
            register={register}
            name="required_hours_per_day"
            label="Required hours per day"
            step="0.5"
            error={errors.required_hours_per_day?.message}
            hint="A full day's hours — for proration, the minimum attendance check and the hourly equivalent."
          />
        </Grid>

        {method === "per_day" && (
          <Grid>
            <Figure register={register} name="daily_amount" label="Daily allowance (₱)" placeholder="500.00" error={errors.daily_amount?.message} />
            <Choice control={control} name="per_day_proration" label="Proration for partial days" options={opts("per_day_proration")} />
          </Grid>
        )}
        {method === "per_hour" && (
          <Grid>
            <Figure register={register} name="hourly_amount" label="Hourly allowance (₱)" placeholder="60.00" error={errors.hourly_amount?.message} />
            <Figure register={register} name="max_paid_hours_per_day" label="Maximum paid hours per day" step="0.5" error={errors.max_paid_hours_per_day?.message} />
          </Grid>
        )}
        {method === "fixed_monthly" && (
          <Grid>
            <Figure register={register} name="monthly_amount" label="Monthly allowance (₱)" placeholder="10000.00" error={errors.monthly_amount?.message} />
            <Choice control={control} name="monthly_proration" label="Proration method" options={opts("monthly_proration")} />
          </Grid>
        )}
      </Section>

      {/* 2 — Attendance rules */}
      <Section
        number={2}
        title="Attendance Rules"
        description="How attendance affects the allowance. Lateness is measured against each intern's work schedule and its grace period, as it is for employees."
      >
        <Grid>
          <Choice control={control} name="late_rule" label="Late attendance" options={opts("late_rule")} disabled={hoursBased} hint={minuteRuleHint} />
          {!hoursBased && values.late_rule === "fixed_amount" && (
            <Figure register={register} name="late_fixed_amount" label="Late charge (₱ per late day)" error={errors.late_fixed_amount?.message} />
          )}
          {!hoursBased && values.late_rule === "partial_after_minutes" && (
            <>
              <Figure register={register} name="late_partial_after_minutes" label="Partial after (minutes late)" step="1" error={errors.late_partial_after_minutes?.message} />
              <Choice control={control} name="partial_day_pricing" label="A partial day is" options={opts("partial_day_pricing")} />
            </>
          )}
        </Grid>
        <Grid>
          <Choice control={control} name="undertime_rule" label="Undertime" options={opts("undertime_rule")} disabled={hoursBased} hint={minuteRuleHint} />
          {!hoursBased && values.undertime_rule === "fixed_amount" && (
            <Figure register={register} name="undertime_fixed_amount" label="Undertime charge (₱ per day)" error={errors.undertime_fixed_amount?.message} />
          )}
          <Choice control={control} name="early_out_rule" label="Approved early out" options={opts("early_out_rule")} disabled={hoursBased} hint={minuteRuleHint} />
        </Grid>
        <Grid>
          <Choice control={control} name="absence_rule" label="Absence" options={opts("absence_rule").filter((o) => o.value !== "absent_hours" || method === "fixed_monthly")} />
          <Choice control={control} name="half_day_rule" label="Half-day" options={opts("half_day_rule")} disabled={hoursBased} hint={minuteRuleHint} />
          <Choice control={control} name="late_penalty_rule" label="Tardiness penalty (N lates = 1 day)" options={opts("late_penalty_rule")} />
        </Grid>
      </Section>

      {/* 3 — Non-working days */}
      <Section
        number={3}
        title="Non-Working Days"
        description="Allowance treatment for days the intern is not expected to work, and for time worked beyond the schedule."
      >
        <Grid>
          <Choice
            control={control}
            name="premium_day_basis"
            label="A premium day is worth"
            options={opts("premium_day_basis")}
            hint="Applies to every worked rest day, holiday and suspension below, before its premium rate."
          />
        </Grid>

        <SubHeading>Rest days</SubHeading>
        <Grid>
          <Choice control={control} name="rest_day_worked" label="Rest day worked" options={opts("rest_day_worked")} />
          {premiumOn(values, "rest_day") && <PremiumRate prefix="rest_day" control={control} register={register} opts={opts} errors={errors} source={values.rest_day_premium_source} />}
        </Grid>

        {(["regular_holiday", "special_holiday"] as const).map((prefix) => (
          <div key={prefix} className="space-y-4">
            <SubHeading>{prefix === "regular_holiday" ? "Regular holidays" : "Special non-working holidays"}</SubHeading>
            <Grid>
              <Choice control={control} name={`${prefix}_not_worked`} label="Not worked (on a workday)" options={opts(`${prefix}_not_worked`)} />
              <Choice control={control} name={`${prefix}_worked`} label="Worked" options={opts(`${prefix}_worked`)} />
              <Choice control={control} name={`${prefix}_rest_day_not_worked`} label="Falls on a rest day, not worked" options={opts(`${prefix}_rest_day_not_worked`)} />
              {premiumOn(values, prefix) && <PremiumRate prefix={prefix} control={control} register={register} opts={opts} errors={errors} source={values[`${prefix}_premium_source`]} />}
            </Grid>
          </div>
        ))}

        <Grid>
          <Choice control={control} name="special_working_day" label="A special working day is" options={opts("special_working_day")} />
          <div className="space-y-1.5">
            <Label>Regular holiday after an absence</Label>
            <label className="flex items-start gap-2.5 rounded-lg border border-input p-3">
              <input type="checkbox" className="mt-0.5 size-4 shrink-0 rounded border-input accent-brand" {...register("holiday_forfeit_after_absence")} />
              <span className="text-sm">No allowance for a regular holiday when the workday before it was an unpaid absence</span>
            </label>
          </div>
        </Grid>

        <SubHeading>Company-declared non-working days</SubHeading>
        <Grid>
          <Choice control={control} name="suspension_not_worked" label="Not worked" options={opts("suspension_not_worked")} />
          <Choice control={control} name="suspension_worked" label="Worked" options={opts("suspension_worked")} />
          {premiumOn(values, "suspension") && <PremiumRate prefix="suspension" control={control} register={register} opts={opts} errors={errors} source={values.suspension_premium_source} />}
        </Grid>

        <SubHeading>Overtime &amp; night differential</SubHeading>
        <Grid>
          <Choice control={control} name="overtime_rule" label="Overtime" options={opts("overtime_rule")} />
          {premiumOn(values, "overtime") && <PremiumRate prefix="overtime" control={control} register={register} opts={opts} errors={errors} source={values.overtime_premium_source} />}
          <Choice control={control} name="night_diff_rule" label="Night differential" options={opts("night_diff_rule")} />
          {values.night_diff_rule === "custom" && (
            <Figure register={register} name="night_diff_pct" label="Night differential (% of hourly allowance)" error={errors.night_diff_pct?.message} />
          )}
        </Grid>
      </Section>

      {/* 4 — Attendance requirement */}
      <Section
        number={4}
        title="Attendance Requirement"
        description="Checked per day: the hours rendered that day against the required hours per day."
      >
        <Grid>
          <Figure register={register} name="min_attendance_pct" label="Minimum required attendance (%)" step="1" error={errors.min_attendance_pct?.message} hint="0 switches the check off." />
          {Number(values.min_attendance_pct) > 0 && (
            <Choice control={control} name="below_min_attendance" label="Below the minimum" options={opts("below_min_attendance")} />
          )}
        </Grid>
        <div className="grid grid-cols-1 gap-4 rounded-lg border border-border bg-muted/40 p-4 text-sm sm:grid-cols-2">
          <div className="space-y-1">
            <p className="font-medium text-foreground">Grace period &amp; missing clock-out</p>
            <p className="text-muted-foreground">
              From each intern&rsquo;s work schedule, as for employees. A forgotten clock-out is
              flagged for review and not deducted.
            </p>
          </div>
          <div className="space-y-1">
            <p className="font-medium text-foreground">Eligible · Ineligible</p>
            <p className="text-muted-foreground">
              Present, late and half-day count toward attendance; absent days never do. Leave is
              priced by the leave rules below.
            </p>
          </div>
        </div>
      </Section>

      {/* 5 — Leave & distribution */}
      <Section
        number={5}
        title="Leave & Allowance Distribution"
        description="Interns file leave without credits. The allowance is paid inside the regular payroll runs — no separate intern payroll."
      >
        <Grid>
          <Choice control={control} name="leave_pay_rule" label="Leave is" options={opts("leave_pay_rule")} />
          <Choice control={control} name="leave_types_allowed" label="Interns may file" options={opts("leave_types_allowed")} />
          {values.leave_types_allowed === "selected" && (
            <div className="space-y-1.5">
              <Label htmlFor="leave_type_ids">Leave types</Label>
              <Controller
                control={control}
                name="leave_type_ids"
                render={({ field }) => (
                  <MultiCombobox
                    id="leave_type_ids"
                    options={(leaveTypes ?? []).map((t) => ({ value: String(t.id), label: t.name }))}
                    selected={field.value}
                    onChange={field.onChange}
                    placeholder="Select leave types…"
                  />
                )}
              />
              <FieldError message={errors.leave_type_ids?.message} />
            </div>
          )}
        </Grid>
        <Grid>
          <Choice
            control={control}
            name="distribution_timing"
            label="Distribution"
            options={opts("distribution_timing").filter((o) => o.value !== "first_cutoff" || method === "fixed_monthly")}
            hint="Which cutoff of the month pays the allowance, in each intern's own pay group."
          />
        </Grid>
      </Section>

      {/* 6 — Calculation preview */}
      <InternCalculationPreview settingsJson={settingsJson} />

      {/* 7 — Effective date */}
      <Section
        number={7}
        title="Effective Date"
        description="Applies to all interns from this date. Today or later — a period already paid is corrected on its payslips, not by rewriting its rules. Every save is recorded in the audit log."
      >
        <div className="flex flex-wrap items-end gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="effective_from">Settings effective from</Label>
            <Controller
              control={control}
              name="effective_from"
              render={({ field }) => (
                <DatePicker id="effective_from" value={field.value} onChange={field.onChange} min={manilaToday()} />
              )}
            />
            <FieldError message={errors.effective_from?.message} />
          </div>
          <Button type="submit" disabled={isSubmitting || saveMut.isPending || !isDirty}>
            {isSubmitting || saveMut.isPending ? "Saving…" : "Save Changes"}
          </Button>
        </div>
      </Section>

      <Dialog open={overrideQuestion !== null} onOpenChange={(o) => !o && setOverrideQuestion(null)}>
        <DialogContent className="max-w-[calc(100%-3rem)] sm:max-w-[460px]">
          <DialogHeader>
            <DialogTitle>Interns have their own allowance</DialogTitle>
            <DialogDescription>Changing the calculation method re-reads their amounts in the new unit.</DialogDescription>
          </DialogHeader>
          <div className="flex items-start gap-2 rounded-lg border border-warning bg-warning-subtle p-3">
            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden />
            <p className="text-xs text-foreground">{overrideQuestion?.message}</p>
          </div>
          <DialogFooter>
            <Button type="button" variant="ghost" onClick={() => setOverrideQuestion(null)}>
              Cancel
            </Button>
            <Button
              type="button"
              disabled={saveMut.isPending}
              onClick={async () => {
                const pending = overrideQuestion;
                setOverrideQuestion(null);
                if (pending) await save(pending.values, true);
              }}
            >
              Save anyway
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </form>
  );
}

/** A premium's source and — when custom — its rate. */
function PremiumRate({
  prefix,
  control,
  register,
  opts,
  errors,
  source,
}: {
  prefix: PremiumPrefix;
  control: Control<FormValues>;
  register: UseFormRegister<FormValues>;
  opts: (key: string) => { value: string; label: string }[];
  errors: Partial<Record<keyof FormValues, { message?: string }>>;
  source: string;
}) {
  const pctKey = `${prefix}_premium_pct` as Path<FormValues>;

  return (
    <>
      <Choice control={control} name={`${prefix}_premium_source` as Path<FormValues>} label="Premium rate" options={opts(`${prefix}_premium_source`)} />
      {source === "custom" && (
        <Figure
          register={register}
          name={pctKey}
          label="Custom rate (% of the allowance)"
          step="1"
          placeholder="130"
          error={errors[pctKey as keyof FormValues]?.message}
          hint="The whole day's rate: 130 = the allowance plus 30%."
        />
      )}
    </>
  );
}
