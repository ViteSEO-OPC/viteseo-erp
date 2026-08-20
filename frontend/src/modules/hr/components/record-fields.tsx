"use client";

import { Controller, type FieldValues, type Path, type UseFormReturn } from "react-hook-form";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { DatePicker } from "@/modules/hr/ui/date-picker";

/**
 * The employee record shows each field as a label + value, and swaps that value for an
 * input when the section is being edited. One spec drives both states so the read and
 * write views can never drift apart.
 */
export interface FieldSpec<T extends FieldValues> {
  name: Path<T>;
  label: string;
  kind?: "text" | "email" | "number" | "select" | "date";
  options?: { value: string; label: string }[];
  placeholder?: string;
  /** Display text when not editing. Defaults to the option label, else the raw value. */
  display?: string | null;
  /** Shown but never editable (e.g. a system-assigned ID). */
  readOnly?: boolean;
  /** Date bounds, e.g. max={today} for a birthday. */
  maxDate?: string;
  fromYear?: number;
  /** Allow clearing a select back to "not set". */
  clearable?: boolean;
  hint?: string;
}

/* `min-w-0` + `break-words` is the pair that keeps a value inside its card. */
export function Detail({ label, value }: { label: string; value?: string | null }) {
  return (
    <div className="min-w-0">
      {/*
       * The label breaks too, not just the value: "University/College" and
       * "Health concern/condition" are ~160px at 13px uppercase, and nothing in
       * them is a break opportunity a browser will take on its own.
       */}
      <p className="text-label font-bold uppercase tracking-wide break-words text-muted-foreground">
        {label}
      </p>
      <p className="mt-1 text-sm break-words text-foreground">{value || "—"}</p>
    </div>
  );
}

/* Column counts come from the *card's* width, not the viewport's. */
const FIELD_GRID = "grid grid-cols-1 gap-x-8 gap-y-5 @md/fields:grid-cols-2";

// A titled subsection (teal header) with a detail grid, matching the expanded
// personal record. `divider` adds a top rule to separate stacked sections.
export function Section({
  title,
  divider,
  children,
}: {
  title: string;
  divider?: boolean;
  children: React.ReactNode;
}) {
  return (
    <div className={cn(divider && "border-t border-border pt-6")}>
      <p className="mb-4 text-label font-semibold uppercase tracking-wide text-brand">{title}</p>
      <div className="@container/fields">
        <div className={cn(FIELD_GRID, "@3xl/fields:grid-cols-3")}>{children}</div>
      </div>
    </div>
  );
}

/** The grid of fields — read-only text, or inputs once `editing` is on. */
export function FieldGrid<T extends FieldValues>({
  specs,
  editing,
  form,
  columns = 3,
}: {
  specs: FieldSpec<T>[];
  editing: boolean;
  form: UseFormReturn<T>;
  columns?: 3 | 4;
}) {
  return (
    // See FIELD_GRID above for why these are container queries.
    <div className="@container/fields">
      <div
        className={cn(
          FIELD_GRID,
          "@3xl/fields:grid-cols-3",
          columns === 4 && "@5xl/fields:grid-cols-4",
        )}
      >
        {specs.map((spec) => (
          <RecordField key={String(spec.name)} spec={spec} editing={editing} form={form} />
        ))}
      </div>
    </div>
  );
}

export function RecordField<T extends FieldValues>({
  spec,
  editing,
  form,
}: {
  spec: FieldSpec<T>;
  editing: boolean;
  form: UseFormReturn<T>;
}) {
  const raw = form.watch(spec.name) as unknown;
  const value = raw == null ? "" : String(raw);

  if (!editing || spec.readOnly) {
    return <Detail label={spec.label} value={displayValue(spec, value)} />;
  }

  const error = errorFor(form, spec.name);

  return (
    // `min-w-0` for the same reason as `Detail`: an `<input>` carries an
    // intrinsic width from its default `size`, which would otherwise set a floor
    // under the column and widen the grid past the card while editing.
    <div className="min-w-0 space-y-1.5">
      <label className="block text-label font-bold uppercase tracking-wide break-words text-muted-foreground">
        {spec.label}
      </label>
      {spec.kind === "select" ? (
        <Controller
          control={form.control}
          name={spec.name}
          render={({ field }) => (
            <SimpleSelect
              value={field.value || (spec.clearable ? NONE : "")}
              onValueChange={(v) => field.onChange(v === NONE ? "" : v)}
              placeholder="Select…"
              aria-invalid={!!error}
              options={
                spec.clearable
                  ? [{ value: NONE, label: "None" }, ...withCurrent(spec, field.value)]
                  : withCurrent(spec, field.value)
              }
            />
          )}
        />
      ) : spec.kind === "date" ? (
        <Controller
          control={form.control}
          name={spec.name}
          render={({ field }) => (
            <DatePicker
              value={field.value}
              onChange={field.onChange}
              max={spec.maxDate}
              fromYear={spec.fromYear}
              aria-invalid={!!error}
            />
          )}
        />
      ) : (
        <Input
          type={spec.kind === "number" ? "number" : spec.kind === "email" ? "email" : "text"}
          min={spec.kind === "number" ? "0" : undefined}
          step={spec.kind === "number" ? "0.01" : undefined}
          placeholder={spec.placeholder}
          aria-invalid={!!error}
          {...form.register(spec.name)}
        />
      )}
      {spec.hint && !error && <p className="text-caption text-muted-foreground">{spec.hint}</p>}
      {error && <p className="text-xs text-destructive">{error}</p>}
    </div>
  );
}

/**
 * The frame around one tab's fields: heading, the Edit / Save / Cancel affordance, and
 * the form element that submits the section. Editing happens in place — no dialog.
 */
export function RecordSectionShell({
  title,
  editable,
  editing,
  saving,
  onEdit,
  onCancel,
  onSubmit,
  headerExtra,
  children,
}: {
  title: string;
  /** False for the employee's own read-only view. */
  editable: boolean;
  editing: boolean;
  saving: boolean;
  onEdit: () => void;
  onCancel: () => void;
  onSubmit: (event?: React.BaseSyntheticEvent) => void;
  /** Extra header control, e.g. self-service "Request Change". */
  headerExtra?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <form
      noValidate
      onSubmit={(event) => {
        event.preventDefault();
        onSubmit(event);
      }}
    >
      <div className="mb-5 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <h2 className="text-h3 font-bold text-foreground">{title}</h2>
        <div className="flex items-center gap-2">
          {headerExtra}
          {editable &&
            (editing ? (
              <>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={saving}
                  onClick={onCancel}
                >
                  Cancel
                </Button>
                <Button type="submit" size="sm" disabled={saving}>
                  {saving ? "Saving…" : "Save changes"}
                </Button>
              </>
            ) : (
              <Button type="button" variant="link" size="sm" onClick={onEdit}>
                Edit
              </Button>
            ))}
        </div>
      </div>
      {children}
    </form>
  );
}

// Radix Select has no concept of an empty value, so "not set" needs a sentinel.
const NONE = "__none__";

function displayValue<T extends FieldValues>(spec: FieldSpec<T>, value: string): string | null {
  if (spec.display !== undefined) return spec.display;
  if (spec.options) return spec.options.find((o) => o.value === value)?.label ?? value;
  return value;
}

/**
 * Keep a stored value that is no longer offered (a renamed position, a gender captured
 * before the list was fixed) selectable, so editing a neighbouring field can't silently
 * drop it.
 */
function withCurrent<T extends FieldValues>(spec: FieldSpec<T>, value: unknown) {
  const options = spec.options ?? [];
  const current = typeof value === "string" ? value : "";

  return current && !options.some((o) => o.value === current)
    ? [...options, { value: current, label: `${current} (current)` }]
    : options;
}

function errorFor<T extends FieldValues>(form: UseFormReturn<T>, name: Path<T>): string | undefined {
  const entry = (form.formState.errors as Record<string, { message?: string } | undefined>)[
    String(name)
  ];
  return entry?.message;
}
