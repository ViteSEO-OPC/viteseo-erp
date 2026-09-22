"use client";

import { useRef } from "react";
import { AlignCenter, AlignLeft, AlignRight, RotateCcw } from "lucide-react";
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { FIELD } from "@/modules/crm/features/settings/components/form-styles";
import { cn } from "@/modules/crm/lib/utils";
import { insertAt } from "@/modules/crm/features/email-templates/lib/email-template-text";
import { isAllowedLinkUrl, TEXT_LIMITS } from "@/modules/crm/features/email-templates/lib/email-design";
import type { DesignAlignment } from "@/modules/crm/features/email-templates/types/email-design-types";
import type { TemplateToken } from "@/modules/crm/features/email-templates/types/email-template-types";

/**
 * The small, typed controls the designer's settings are built from. Each one can only produce a value
 * the design schema accepts: a choice from a fixed list, a whole number inside its range, a six-digit
 * hex color from the native picker — or, for a style, nothing at all, meaning "inherit". Free text is the
 * one exception, and the server checks it.
 */

const SEGMENT_BUTTON =
  "inline-flex items-center justify-center gap-1 rounded-md px-2 py-1 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50";

export function SegmentedChoice<T extends string | number>({
  label,
  value,
  options,
  onChange,
  disabled,
  error,
}: {
  label: string;
  value: T;
  options: readonly { value: T; label: React.ReactNode; title: string }[];
  onChange: (value: T) => void;
  disabled?: boolean;
  error?: string;
}) {
  return (
    <FormField label={label} error={error}>
      <div role="group" aria-label={label} className="inline-flex w-fit items-center gap-1 rounded-lg border border-border bg-muted p-1">
        {options.map((option) => (
          <button
            key={String(option.value)}
            type="button"
            aria-pressed={value === option.value}
            aria-label={option.title}
            title={option.title}
            onClick={() => onChange(option.value)}
            disabled={disabled}
            className={cn(
              SEGMENT_BUTTON,
              value === option.value ? "bg-card text-foreground shadow-sm" : "text-muted-foreground hover:text-foreground",
            )}
          >
            {option.label}
          </button>
        ))}
      </div>
    </FormField>
  );
}

const ALIGNMENT_OPTIONS = [
  { value: "left", label: <AlignLeft className="size-3.5" aria-hidden />, title: "Align left" },
  { value: "center", label: <AlignCenter className="size-3.5" aria-hidden />, title: "Align center" },
  { value: "right", label: <AlignRight className="size-3.5" aria-hidden />, title: "Align right" },
] as const satisfies readonly { value: DesignAlignment; label: React.ReactNode; title: string }[];

export function AlignmentControl({
  value,
  onChange,
  disabled,
  error,
}: {
  value: DesignAlignment;
  onChange: (value: DesignAlignment) => void;
  disabled?: boolean;
  error?: string;
}) {
  return <SegmentedChoice label="Alignment" value={value} options={ALIGNMENT_OPTIONS} onChange={onChange} disabled={disabled} error={error} />;
}

/**
 * A style color. Unset, it shows the color it inherits, marked "Default"; picking a color sets it, and the
 * reset button clears it again so it inherits. The native picker only ever yields a six-digit hex, which is
 * exactly what the schema takes.
 */
export function ColorControl({
  label,
  value,
  inherited,
  onChange,
  disabled,
  error,
}: {
  label: string;
  /** The color this template sets, or undefined when it inherits. */
  value: string | undefined;
  /** What an unset value resolves to — shown, and the picker's starting color. */
  inherited: string;
  onChange: (value: string | undefined) => void;
  disabled?: boolean;
  error?: string;
}) {
  const effective = (value ?? inherited).toLowerCase();

  return (
    <FormField label={label} error={error}>
      <div className="flex h-9 w-fit items-center gap-1">
        <label className="flex h-9 w-fit items-center gap-2 rounded-lg border border-border bg-muted pr-3 pl-1.5">
          <input
            type="color"
            value={effective}
            onChange={(event) => onChange(event.target.value.toLowerCase())}
            disabled={disabled}
            aria-label={label}
            className="size-6 cursor-pointer rounded border-0 bg-transparent p-0 disabled:cursor-not-allowed"
          />
          <span className="font-mono text-xs text-foreground">{effective}</span>
          {value === undefined ? <span className="text-[11px] text-muted-foreground">Default</span> : null}
        </label>
        {value !== undefined ? (
          <button
            type="button"
            onClick={() => onChange(undefined)}
            disabled={disabled}
            aria-label={`Reset ${label} to the default`}
            title="Use the default"
            className="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-40"
          >
            <RotateCcw className="size-3.5" aria-hidden />
          </button>
        ) : null}
      </div>
    </FormField>
  );
}

export function RangeControl({
  label,
  value,
  range,
  unit = "px",
  onChange,
  disabled,
  error,
}: {
  label: string;
  value: number;
  range: readonly [number, number];
  unit?: string;
  onChange: (value: number) => void;
  disabled?: boolean;
  error?: string;
}) {
  return (
    <FormField label={label} error={error}>
      <div className="flex h-9 items-center gap-3">
        <input
          type="range"
          min={range[0]}
          max={range[1]}
          step={1}
          value={value}
          onChange={(event) => onChange(Number(event.target.value))}
          disabled={disabled}
          aria-label={label}
          aria-valuetext={`${value}${unit}`}
          className="w-full accent-teal-600"
        />
        <span className="w-14 shrink-0 text-right font-mono text-xs text-foreground">
          {value}
          {unit}
        </span>
      </div>
    </FormField>
  );
}

/** Placeholder chips. `onMouseDown` keeps the caret where it is while a chip is pressed. */
export function TokenChips({
  tokens,
  onInsert,
  disabled,
}: {
  tokens: readonly TemplateToken[];
  onInsert: (key: string) => void;
  disabled?: boolean;
}) {
  return (
    <div className="flex flex-wrap gap-1">
      {tokens.map((token) => (
        <button
          key={token.key}
          type="button"
          onMouseDown={(event) => event.preventDefault()}
          onClick={() => onInsert(token.key)}
          disabled={disabled}
          title={`${token.label} — e.g. ${token.sample}`}
          className="rounded-md border border-border bg-muted px-1.5 py-0.5 font-mono text-[11px] text-foreground transition-colors hover:bg-teal-400/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
        >
          {`{{${token.key}}}`}
        </button>
      ))}
    </div>
  );
}

/** A single-line text field; with tokens, a row of placeholder chips that insert at the caret. */
export function TextLineControl({
  label,
  value,
  onChange,
  tokens = [],
  maxLength,
  placeholder,
  required,
  disabled,
  error,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  tokens?: readonly TemplateToken[];
  maxLength: number;
  placeholder?: string;
  required?: boolean;
  disabled?: boolean;
  error?: string;
}) {
  const inputRef = useRef<HTMLInputElement>(null);

  function insertToken(key: string) {
    const input = inputRef.current;
    const next = insertAt(value, input?.selectionStart ?? value.length, input?.selectionEnd ?? value.length, `{{${key}}}`);
    onChange(next.value);
    requestAnimationFrame(() => {
      input?.focus();
      input?.setSelectionRange(next.caret, next.caret);
    });
  }

  return (
    <FormField label={label} required={required} error={error}>
      <div className="flex flex-col gap-1.5">
        <input
          ref={inputRef}
          value={value}
          onChange={(event) => onChange(event.target.value)}
          maxLength={maxLength}
          placeholder={placeholder}
          disabled={disabled}
          aria-label={label}
          className={FIELD}
        />
        {tokens.length > 0 ? <TokenChips tokens={tokens} onInsert={insertToken} disabled={disabled} /> : null}
      </div>
    </FormField>
  );
}

/** A link field. It hints at the https / mailto / tel rule as you type; the server has the final word. */
export function LinkControl({
  label,
  value,
  onChange,
  optional,
  disabled,
  error,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  optional?: boolean;
  disabled?: boolean;
  error?: string;
}) {
  const hint =
    !error && value.trim() !== "" && !isAllowedLinkUrl(value.trim())
      ? "Use a full https:// address, a mailto: address or a tel: number."
      : undefined;

  return (
    <FormField label={label} optional={optional} required={!optional} error={error ?? hint}>
      <input
        type="url"
        inputMode="url"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder="https://"
        maxLength={TEXT_LIMITS.url}
        disabled={disabled}
        aria-label={label}
        className={FIELD}
      />
    </FormField>
  );
}
