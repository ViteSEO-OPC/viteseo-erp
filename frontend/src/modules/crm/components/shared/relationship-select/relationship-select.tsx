"use client";

import type { Ref } from "react";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
  SelectSeparator,
} from "@/modules/crm/components/ui/select";

/** One selectable option: the stored `value` (a foreign-key id or a sentinel) + its human label. */
export type RelationshipOption = { value: string; label: string };

/**
 * Internal sentinel for the optional footer action row. Namespaced so it can never collide with a
 * real option value (a numeric foreign key, or a niche name). It is intercepted in `onValueChange`
 * and never propagates, so the caller's value is untouched when the action row is chosen.
 */
const FOOTER_ACTION_VALUE = "__relationship-select:footer-action__";

/** An action row rendered below the options, under a separator — e.g. "+ Add Business Niche…". */
export type RelationshipFooterAction = {
  label: string;
  onSelect: () => void;
};

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
  /**
   * Optional action row rendered last, below a separator. Choosing it fires `onSelect` and does NOT
   * change the selected value — it is an action, not an option.
   */
  footerAction?: RelationshipFooterAction;
  /**
   * Shown in place of the option list when `options` is empty — so an empty dropdown explains
   * itself instead of appearing broken. Non-interactive.
   */
  emptyLabel?: string;
  /**
   * Forwarded to the trigger. Lets a caller return focus to the closed select after an action row
   * opened a dialog, so the keyboard user lands back where they were instead of at the page top.
   */
  triggerRef?: Ref<HTMLButtonElement>;
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
 * cannot regress per call site. Label resolution is presentation only — it changes no value or payload.
 *
 * `footerAction` optionally appends one action row below a separator (the Business Niche selector's
 * "+ Add Business Niche…" inline-create entry point). It is opt-in and intercepted before
 * `onValueChange`, so a caller that omits it gets exactly the previous behaviour.
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
  footerAction,
  emptyLabel,
  triggerRef,
}: RelationshipSelectProps) {
  // Always resolve to a concrete label — matched option → caller fallback → placeholder → "" —
  // so a numeric foreign key can never be rendered on the trigger.
  const displayLabel =
    options.find((option) => option.value === value)?.label ?? fallbackLabel ?? placeholder ?? "";

  return (
    <Select
      value={value}
      onValueChange={(next) => {
        // The footer action is a command, not a value: run it and leave the selection alone.
        if (next === FOOTER_ACTION_VALUE) {
          footerAction?.onSelect();
          return;
        }
        onValueChange(typeof next === "string" ? next : "");
      }}
      disabled={disabled}
    >
      <SelectTrigger ref={triggerRef} id={id} aria-label={ariaLabel} className={triggerClassName}>
        {/* Empty value → undefined child → base-ui shows the (muted) placeholder.
            Non-empty value → our resolved label, so the raw id is never shown. */}
        <SelectValue placeholder={placeholder}>{value ? displayLabel : undefined}</SelectValue>
      </SelectTrigger>
      <SelectContent>
        {/* The OPTIONS scroll; the footer action does not. Capping the list at ~8 rows keeps a
            registry that grows over time usable, and leaving the action outside this container
            pins it as the last row instead of letting it drift out of view mid-scroll. */}
        <div className="max-h-56 overflow-y-auto">
          {options.length === 0 && emptyLabel ? (
            <p className="px-1.5 py-2 text-sm text-muted-foreground">{emptyLabel}</p>
          ) : (
            options.map((option) => (
              <SelectItem key={option.value} value={option.value}>
                {option.label}
              </SelectItem>
            ))
          )}
        </div>
        {footerAction ? (
          <>
            <SelectSeparator />
            <SelectItem value={FOOTER_ACTION_VALUE} className="text-teal-600 dark:text-teal-400">
              {footerAction.label}
            </SelectItem>
          </>
        ) : null}
      </SelectContent>
    </Select>
  );
}
