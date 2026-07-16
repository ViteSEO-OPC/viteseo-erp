"use client";

import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from "@/modules/crm/components/ui/select";

/** One selectable option: the stored `value` (a foreign-key id or a sentinel) + its human label. */
export type RelationshipOption = { value: string; label: string };

type RelationshipSelectProps = {
  /** Currently stored value — a foreign-key id or a sentinel like "all" / "unassigned". */
  value: string;
  /** Receives ONLY the id / sentinel — callers keep storing the foreign key, never the label. */
  onValueChange: (value: string) => void;
  /** The full option set. Trigger label AND dropdown items are both derived from this — one source. */
  options: RelationshipOption[];
  placeholder?: string;
  /**
   * Label to show when `value` is set but is not present in `options` (e.g. an assignee outside a
   * paginated options list). Guarantees a raw id can never surface even in that edge case.
   */
  fallbackLabel?: string;
  disabled?: boolean;
  triggerClassName?: string;
  id?: string;
  "aria-label"?: string;
};

/**
 * Lead Relationship Selector Standard — the single owner of relationship/entity `<Select>`s.
 *
 * Guarantees the closed trigger ALWAYS shows the selected option's human label while the value
 * submitted upstream stays the foreign-key id. base-ui's `SelectValue` otherwise renders the raw
 * `value` (the id) until its portal `SelectItem`s mount, so we resolve the label from `options`
 * ourselves and pass it as the `SelectValue` child (base-ui renders a provided child verbatim,
 * bypassing the portal race). Use this for every Lead-module selector that displays another entity
 * (Assigned BD / user / created-by / archived-by) so the label-resolution lives in one place and
 * cannot regress per call site. This is presentation only — it changes no value, payload, or logic.
 */
export function RelationshipSelect({
  value,
  onValueChange,
  options,
  placeholder,
  fallbackLabel,
  disabled,
  triggerClassName,
  id,
  "aria-label": ariaLabel,
}: RelationshipSelectProps) {
  // Always resolve to a concrete label — matched option → caller fallback → placeholder → "" —
  // so a numeric foreign key can never be rendered on the trigger.
  const displayLabel =
    options.find((option) => option.value === value)?.label ?? fallbackLabel ?? placeholder ?? "";

  return (
    <Select
      value={value}
      onValueChange={(next) => onValueChange(typeof next === "string" ? next : "")}
      disabled={disabled}
    >
      <SelectTrigger id={id} aria-label={ariaLabel} className={triggerClassName}>
        {/* Empty value → undefined child → base-ui shows the (muted) placeholder.
            Non-empty value → our resolved label, so the raw id is never shown. */}
        <SelectValue placeholder={placeholder}>{value ? displayLabel : undefined}</SelectValue>
      </SelectTrigger>
      <SelectContent>
        {options.map((option) => (
          <SelectItem key={option.value} value={option.value}>
            {option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
