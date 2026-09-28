"use client";

import { useEffect } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { Textarea } from "@/core/ui/textarea";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { cn } from "@/lib/utils";
import {
  useCreateLeaveType,
  useUpdateLeaveType,
} from "@/modules/hr/hooks/use-leave-config";
import type { LeaveType } from "@/modules/hr/types/leave";

// Suggested swatches, not the only choices — the field below accepts any hex. `color` is stored
// as a hex string, so these ARE data values (mirrors the leave-type seeder), not styling tokens.
const COLOR_OPTIONS = [
  { name: "Teal", value: "#4FDBD7" },
  { name: "Green", value: "#22C55E" },
  { name: "Blue", value: "#2563EB" },
  { name: "Amber", value: "#F0A608" },
  { name: "Red", value: "#EF4444" },
  { name: "Purple", value: "#8B5CF6" },
  { name: "Pink", value: "#EC4899" },
  { name: "Gray", value: "#6B7280" },
] as const;

const DEFAULT_COLOR = "#4FDBD7";

const schema = z.object({
  name: z.string().min(1, "Name is required").max(255),
  code: z.string().min(1, "Code is required").max(50),
  is_paid: z.boolean(),
  // Whether the type draws down credits at all. Independent of `is_paid`: paid/unpaid is what
  // payroll does with the day, unlimited is whether there is an allowance to run out of.
  is_unlimited: z.boolean(),
  // Mirrors the server rule, so a bad hex is caught before the round trip.
  color: z
    .string()
    .regex(/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/, "Enter a hex code such as #4FDBD7")
    .optional(),
  description: z.string().max(1000).optional(),
  allow_backdated: z.boolean(),
  // Kept as the raw text-input value; "" = no limit. Parsed to number | null on submit.
  backdate_limit_days: z
    .union([
      z.coerce.number().int().min(1, "At least 1 day").max(365, "At most 365 days"),
      z.literal(""),
    ])
    .optional(),
  // Accrual. "none" keeps this type hand-managed exactly as before, which is the default
  // for every existing type: nothing starts moving entitlements on its own.
  accrual_mode: z.enum(["none", "monthly", "annual"]),
  prorate_first_year: z.boolean(),
  // Both kept as raw text; "" means "no rule", which is NOT the same as 0 ("nothing carries").
  // Neither has a default figure — the cap and its expiry are company policy, not law.
  carry_over_cap_days: z
    .union([z.coerce.number().min(0, "Cannot be negative").max(365), z.literal("")])
    .optional(),
  carry_over_expiry_months: z
    .union([z.coerce.number().int().min(1, "At least 1 month").max(24), z.literal("")])
    .optional(),
});

type FormValues = z.input<typeof schema>;

export function LeaveTypeFormDialog({
  open,
  onOpenChange,
  leaveType,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Present = edit mode; absent = create mode. */
  leaveType?: LeaveType | null;
}) {
  const isEdit = !!leaveType;
  const createMut = useCreateLeaveType();
  const updateMut = useUpdateLeaveType();

  const {
    register,
    handleSubmit,
    reset,
    setError,
    control,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: "",
      code: "",
      is_paid: true,
      is_unlimited: false,
      color: DEFAULT_COLOR,
      description: "",
      allow_backdated: false,
      backdate_limit_days: "",
      accrual_mode: "none",
      prorate_first_year: true,
      carry_over_cap_days: "",
      carry_over_expiry_months: "",
    },
  });

  // Show the day-cap input only when backdated filing is allowed.
  const [allowBackdated, accrualMode, isPaid, colorValue, isUnlimited] = useWatch({
    control,
    name: ["allow_backdated", "accrual_mode", "is_paid", "color", "is_unlimited"],
  });
  const color = colorValue || DEFAULT_COLOR;

  // Fold any stored hex that isn't a preset into the list so it stays selectable
  // and displays with its own swatch (no data loss on edit).
  useEffect(() => {
    if (open) {
      reset(
        leaveType
          ? {
              name: leaveType.name,
              code: leaveType.code,
              is_paid: leaveType.is_paid,
              is_unlimited: leaveType.is_unlimited,
              color: leaveType.color ?? DEFAULT_COLOR,
              description: leaveType.description ?? "",
              allow_backdated: leaveType.allow_backdated,
              backdate_limit_days: leaveType.backdate_limit_days ?? "",
              accrual_mode: leaveType.accrual_mode,
              prorate_first_year: leaveType.prorate_first_year,
              carry_over_cap_days: leaveType.carry_over_cap_days ?? "",
              carry_over_expiry_months: leaveType.carry_over_expiry_months ?? "",
            }
          : {
              name: "",
              code: "",
              is_paid: true,
              is_unlimited: false,
              color: DEFAULT_COLOR,
              description: "",
              allow_backdated: false,
              backdate_limit_days: "",
              accrual_mode: "none",
              prorate_first_year: true,
              carry_over_cap_days: "",
              carry_over_expiry_months: "",
            },
      );
    }
  }, [open, leaveType, reset]);

  async function onSubmit(values: FormValues) {
    const input = {
      name: values.name,
      code: values.code,
      is_paid: values.is_paid,
      is_unlimited: values.is_unlimited,
      color: values.color || null,
      description: values.description?.trim() ? values.description.trim() : null,
      allow_backdated: values.allow_backdated,
      // No cap (or backdating off) → null.
      backdate_limit_days:
        values.allow_backdated && values.backdate_limit_days !== "" && values.backdate_limit_days != null
          ? Number(values.backdate_limit_days)
          : null,
      accrual_mode: values.accrual_mode,
      prorate_first_year: values.prorate_first_year,
      // "" → null, and null means "no rule" (everything carries / never expires). 0 is a real
      // value meaning "nothing carries", so the empty check has to come before the Number().
      carry_over_cap_days:
        values.carry_over_cap_days !== "" && values.carry_over_cap_days != null
          ? Number(values.carry_over_cap_days)
          : null,
      carry_over_expiry_months:
        values.carry_over_expiry_months !== "" && values.carry_over_expiry_months != null
          ? Number(values.carry_over_expiry_months)
          : null,
    };
    try {
      if (leaveType) {
        await updateMut.mutateAsync({ id: leaveType.id, input });
        toast.success("Leave type updated");
      } else {
        await createMut.mutateAsync(input);
        toast.success("Leave type created");
      }
      onOpenChange(false);
    } catch (err) {
      if (err instanceof AxiosError && err.response?.status === 422) {
        const serverErrors = err.response.data?.errors ?? {};
        for (const field of Object.keys(serverErrors)) {
          setError(field as keyof FormValues, { message: serverErrors[field][0] });
        }
      } else {
        toast.error("Something went wrong. Please try again.");
      }
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-[480px]">
        <DialogHeader>
          <DialogTitle>{isEdit ? "Edit Leave Type" : "Add Leave Type"}</DialogTitle>
          <DialogDescription>
            {isEdit
              ? "Update this leave type. Set its default credits per employment status in the allotment defaults table below."
              : "Create a new leave type for employees. Set its default credits per employment status in the allotment defaults table below."}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-5" noValidate>
          {/* Name · Code */}
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="lt-name">Leave type name</Label>
              <Input id="lt-name" placeholder="e.g. Annual Leave" aria-invalid={!!errors.name} {...register("name")} />
              {errors.name && <p className="text-xs text-destructive">{errors.name.message}</p>}
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="lt-code">Code</Label>
              <Input id="lt-code" placeholder="e.g. AL" aria-invalid={!!errors.code} {...register("code")} />
              {errors.code && <p className="text-xs text-destructive">{errors.code.message}</p>}
            </div>
          </div>

          {/*
           * Colour — a native picker beside the hex, so any colour is reachable and the
           * stored value is always visible and typeable. The swatches below are shortcuts.
           */}
          <div className="space-y-1.5">
            <Label htmlFor="lt-color">Color</Label>
            <div className="flex items-center gap-2">
              <input
                type="color"
                aria-label="Pick a colour"
                value={/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(color) ? color : DEFAULT_COLOR}
                onChange={(e) => setValue("color", e.target.value.toUpperCase(), { shouldDirty: true })}
                className="h-8 w-12 shrink-0 cursor-pointer rounded-lg border border-input bg-transparent p-1"
              />
              <Input
                id="lt-color"
                value={color}
                placeholder="#4FDBD7"
                spellCheck={false}
                aria-invalid={!!errors.color}
                onChange={(e) => {
                  // Accept a bare "4FDBD7" too — the # is the part people forget.
                  const raw = e.target.value.trim();
                  const next = raw && !raw.startsWith("#") ? `#${raw}` : raw;
                  setValue("color", next.toUpperCase(), { shouldDirty: true, shouldValidate: true });
                }}
              />
            </div>
            <div className="flex flex-wrap gap-1.5 pt-0.5">
              {COLOR_OPTIONS.map((c) => (
                <button
                  key={c.value}
                  type="button"
                  title={c.name}
                  aria-label={c.name}
                  onClick={() => setValue("color", c.value, { shouldDirty: true, shouldValidate: true })}
                  className={cn(
                    "size-6 rounded-full border-2 transition-transform hover:scale-110",
                    color.toLowerCase() === c.value.toLowerCase()
                      ? "border-foreground"
                      : "border-transparent",
                  )}
                  style={{ backgroundColor: c.value }}
                />
              ))}
            </div>
            {errors.color && <p className="text-xs text-destructive">{errors.color.message}</p>}
          </div>

          {/* Pay type */}
          <div className="space-y-2">
            <Label>Pay type</Label>
            <div className="flex gap-2.5">
              <PayChip
                label="Paid"
                active={isPaid}
                onClick={() => setValue("is_paid", true, { shouldDirty: true })}
              />
              <PayChip
                label="Unpaid"
                active={!isPaid}
                onClick={() => setValue("is_paid", false, { shouldDirty: true })}
              />
            </div>
            <p className="text-xs text-muted-foreground">
              Unpaid leave (LWOP) is deducted from pay by payroll at the daily rate.
            </p>
          </div>

          {/*
           * Credits — deliberately its own question. "Paid" is what payroll does with the day;
           * "Limited" is whether there is an allowance to run out of. All four combinations are
           * real, and unpaid-but-capped ("at most 10 LWOP days a year") had no way to be said.
           */}
          <div className="space-y-2">
            <Label>Credits</Label>
            <div className="flex gap-2.5">
              <PayChip
                label="Limited"
                active={!isUnlimited}
                onClick={() => setValue("is_unlimited", false, { shouldDirty: true })}
              />
              <PayChip
                label="Unlimited"
                active={isUnlimited}
                onClick={() => setValue("is_unlimited", true, { shouldDirty: true })}
              />
            </div>
            <p className="text-xs text-muted-foreground">
              {isUnlimited
                ? "No allowance and no balance check — employees can file this as often as they need. Allotments below are ignored."
                : "Draws down the employee's credits. Anyone allotted 0 days does not get this leave type."}
            </p>
          </div>

          {/* Description */}
          <div className="space-y-1.5">
            <Label htmlFor="lt-description">
              Description <span className="font-normal text-muted-foreground">(optional)</span>
            </Label>
            <Textarea
              id="lt-description"
              rows={3}
              placeholder="Enter leave type description..."
              aria-invalid={!!errors.description}
              {...register("description")}
            />
            {errors.description && (
              <p className="text-xs text-destructive">{errors.description.message}</p>
            )}
          </div>

          {/* Backdated filing (kept — drives past-date validation on filing) */}
          <div className="rounded-lg border border-border p-3">
            <label className="flex items-start gap-2 text-sm">
              <input
                type="checkbox"
                className="mt-0.5 size-4 rounded border-input accent-brand"
                {...register("allow_backdated")}
              />
              <span>
                Allow backdated filing
                <span className="block text-xs text-muted-foreground">
                  Let employees file this leave for dates that already passed (e.g. sick
                  leave). When off, only today or future dates are accepted.
                </span>
              </span>
            </label>

            {allowBackdated && (
              <div className="mt-3 space-y-1.5 pl-6">
                <Label htmlFor="lt-backdate">Max days back</Label>
                <Input
                  id="lt-backdate"
                  type="number"
                  step="1"
                  min="1"
                  max="365"
                  placeholder="Leave blank for no limit"
                  aria-invalid={!!errors.backdate_limit_days}
                  {...register("backdate_limit_days")}
                />
                {errors.backdate_limit_days ? (
                  <p className="text-xs text-destructive">{errors.backdate_limit_days.message}</p>
                ) : (
                  <p className="text-xs text-muted-foreground">
                    How far back a request may reach. Blank = no limit.
                  </p>
                )}
              </div>
            )}
          </div>

          {/*
           * Accrual, carry-over and expiry. Off by default: an existing type keeps being
           * hand-managed until HR deliberately switches it over, because entitlements are what
           * employees plan holidays around and a migration must not move them.
           */}
          <div className="rounded-lg border border-border p-3 space-y-3">
            <div className="space-y-1.5">
              <Label htmlFor="lt-accrual">Accrual</Label>
              <SimpleSelect
                value={accrualMode}
                onValueChange={(v) => setValue("accrual_mode", v as "none" | "monthly" | "annual")}
                options={[
                  { value: "none", label: "Manual — HR sets balances by hand" },
                  { value: "monthly", label: "Monthly — credits 1/12 of the yearly days each month" },
                  { value: "annual", label: "Annual — credits the whole year in January" },
                ]}
              />
              <p className="text-xs text-muted-foreground">
                Runs on the 1st of each month. The yearly figure comes from this type&apos;s allotment
                for the employee&apos;s employment status.
              </p>
            </div>

            {accrualMode !== "none" && (
              <>
                <label className="flex items-start gap-2 text-sm">
                  <input
                    type="checkbox"
                    className="mt-0.5 size-4 rounded border-input accent-brand"
                    {...register("prorate_first_year")}
                  />
                  <span>
                    Pro-rate the first year
                    <span className="block text-xs text-muted-foreground">
                      A mid-year hire accrues from their hire month, not the whole year.
                    </span>
                  </span>
                </label>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div className="space-y-1.5">
                    <Label htmlFor="lt-carry-cap">Carry-over cap (days)</Label>
                    <Input
                      id="lt-carry-cap"
                      type="number"
                      step="0.5"
                      min="0"
                      max="365"
                      placeholder="Blank = carry everything"
                      aria-invalid={!!errors.carry_over_cap_days}
                      {...register("carry_over_cap_days")}
                    />
                    {errors.carry_over_cap_days ? (
                      <p className="text-xs text-destructive">{errors.carry_over_cap_days.message}</p>
                    ) : (
                      <p className="text-xs text-muted-foreground">
                        Blank = all unused days carry. <strong>0</strong> = none do.
                      </p>
                    )}
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="lt-carry-expiry">Carried days expire after (months)</Label>
                    <Input
                      id="lt-carry-expiry"
                      type="number"
                      step="1"
                      min="1"
                      max="24"
                      placeholder="Blank = never expire"
                      aria-invalid={!!errors.carry_over_expiry_months}
                      {...register("carry_over_expiry_months")}
                    />
                    {errors.carry_over_expiry_months ? (
                      <p className="text-xs text-destructive">
                        {errors.carry_over_expiry_months.message}
                      </p>
                    ) : (
                      <p className="text-xs text-muted-foreground">Counted from 1 January.</p>
                    )}
                  </div>
                </div>

                <div className="rounded-lg border border-warning bg-warning-subtle p-3">
                  <p className="text-xs text-foreground">
                    The cap and expiry are <strong>your company&apos;s rules, not the law</strong> —
                    they ship blank on purpose. Philippine law only covers the 5-day Service Incentive
                    Leave (Art. 95), which must be paid out if unused and cannot simply be forfeited.
                    Days already taken are never clawed back by a cap or an expiry.
                  </p>
                </div>
              </>
            )}
          </div>

          <DialogFooter>
            <DialogClose asChild>
              <Button type="button" variant="ghost">Cancel</Button>
            </DialogClose>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Saving…" : isEdit ? "Save changes" : "Add leave type"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function PayChip({
  label,
  active,
  onClick,
}: {
  label: string;
  active: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cn(
        "rounded-full border bg-background px-4 py-1.5 text-sm transition-colors",
        "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50",
        active
          ? "border-2 border-brand font-bold text-foreground"
          : "border-border font-normal text-muted-foreground hover:text-foreground",
      )}
    >
      {label}
    </button>
  );
}
