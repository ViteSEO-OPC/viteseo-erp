"use client";

import { Plus, Trash2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { GENDERS } from "@/modules/hr/types/employee";

/** The most siblings a record may name (mirrors EmployeeService::MAX_SIBLINGS). */
export const MAX_SIBLINGS = 20;

// Radix Select can't hold an empty value, so "not set" needs a sentinel.
const GENDER_NONE = "__none__";

/** A sibling as the form holds it — gender is "" rather than null while editing. */
export interface SiblingDraft {
  name: string;
  gender: string;
}

/**
 * Siblings are a list: any number of named entries plus a stated total, since someone
 * may have four siblings but only care to name two. Fully controlled so both the record's
 * inline editor and the "Add employee" dialog can drive it from their own form state.
 */
export function SiblingsFields({
  siblings,
  onSiblingsChange,
  count,
  onCountChange,
  countError,
  nameErrors,
}: {
  siblings: SiblingDraft[];
  onSiblingsChange: (next: SiblingDraft[]) => void;
  count: string;
  onCountChange: (next: string) => void;
  countError?: string;
  /** Per-row validation messages, indexed like `siblings`. */
  nameErrors?: (string | undefined)[];
}) {
  function update(index: number, patch: Partial<SiblingDraft>) {
    onSiblingsChange(siblings.map((s, i) => (i === index ? { ...s, ...patch } : s)));
  }

  return (
    <div>
      <div className="max-w-xs space-y-1.5">
        <label className="block text-label font-bold uppercase tracking-wide text-muted-foreground">
          Number of siblings
        </label>
        <Input
          type="number"
          min="0"
          placeholder="0"
          value={count}
          aria-invalid={!!countError}
          onChange={(e) => onCountChange(e.target.value)}
        />
        {countError ? (
          <p className="text-xs text-destructive">{countError}</p>
        ) : (
          <p className="text-caption text-muted-foreground">
            The total, even if you don&apos;t name them all below.
          </p>
        )}
      </div>

      <div className="mt-5 space-y-3">
        {siblings.length === 0 && (
          <p className="text-sm text-muted-foreground">No siblings named yet.</p>
        )}

        {siblings.map((sibling, i) => (
          <div key={i} className="flex flex-col gap-3 sm:flex-row sm:items-start">
            <span className="pt-2.5 text-label font-bold uppercase tracking-wide text-muted-foreground sm:w-20">
              Sibling {i + 1}
            </span>
            {/*
             * `min-w-0`: an `<input>` has an intrinsic width from its default
             * `size`, and a flex item's `min-width: auto` turns that into a
             * floor — the row would overflow rather than let the name field
             * shrink.
             */}
            <div className="min-w-0 flex-1 space-y-1.5">
              <Input
                placeholder="Full name"
                value={sibling.name}
                aria-invalid={!!nameErrors?.[i]}
                onChange={(e) => update(i, { name: e.target.value })}
              />
              {nameErrors?.[i] && <p className="text-xs text-destructive">{nameErrors[i]}</p>}
            </div>
            <div className="sm:w-48">
              <SimpleSelect
                value={sibling.gender || GENDER_NONE}
                onValueChange={(v) => update(i, { gender: v === GENDER_NONE ? "" : v })}
                placeholder="Gender"
                options={[
                  { value: GENDER_NONE, label: "Gender not set" },
                  ...GENDERS.map((g) => ({ value: g, label: g })),
                ]}
              />
            </div>
            <Button
              type="button"
              variant="ghost"
              size="icon-sm"
              className="sm:mt-1"
              onClick={() => onSiblingsChange(siblings.filter((_, index) => index !== i))}
              aria-label={`Remove sibling ${i + 1}`}
              title="Remove"
            >
              <Trash2 />
            </Button>
          </div>
        ))}
      </div>

      <Button
        type="button"
        variant="outline"
        size="sm"
        className="mt-4"
        disabled={siblings.length >= MAX_SIBLINGS}
        onClick={() => onSiblingsChange([...siblings, { name: "", gender: "" }])}
      >
        <Plus />
        Add sibling
      </Button>
    </div>
  );
}
