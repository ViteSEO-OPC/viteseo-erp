"use client";

import { Plus, Trash2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

/** The most IDs one record may carry (mirrors EmployeeService::MAX_OTHER_IDS). */
export const MAX_OTHER_IDS = 10;

/** One extra ID as the form holds it — both halves are plain strings while editing. */
export interface OtherIdDraft {
  type: string;
  number: string;
}

/**
 * The IDs the four named columns do not cover: any number of {type, number} pairs, each named by
 * whoever enters it. Fully controlled, so the record's inline editor and any dialog can drive it
 * from their own form state — the same shape as SiblingsFields.
 */
export function OtherIdsFields({
  ids,
  onIdsChange,
  typeErrors,
  numberErrors,
}: {
  ids: OtherIdDraft[];
  onIdsChange: (next: OtherIdDraft[]) => void;
  /** Per-row validation messages, indexed like `ids`. */
  typeErrors?: (string | undefined)[];
  numberErrors?: (string | undefined)[];
}) {
  function update(index: number, patch: Partial<OtherIdDraft>) {
    onIdsChange(ids.map((row, i) => (i === index ? { ...row, ...patch } : row)));
  }

  return (
    <div>
      <div className="space-y-3">
        {ids.length === 0 && <p className="text-sm text-muted-foreground">No other IDs recorded.</p>}

        {ids.map((row, i) => (
          <div key={i} className="flex flex-col gap-3 sm:flex-row sm:items-start">
            <span className="pt-2.5 text-label font-bold uppercase tracking-wide text-muted-foreground sm:w-16">
              ID {i + 1}
            </span>
            {/*
             * `min-w-0`: an <input> carries an intrinsic width from its default `size`, and a flex
             * item's `min-width: auto` turns that into a floor — the row would overflow rather than
             * let these two fields shrink.
             */}
            <div className="min-w-0 flex-1 space-y-1.5">
              <Input
                placeholder="ID type, e.g. Passport"
                value={row.type}
                aria-invalid={!!typeErrors?.[i]}
                onChange={(e) => update(i, { type: e.target.value })}
              />
              {typeErrors?.[i] && <p className="text-xs text-destructive">{typeErrors[i]}</p>}
            </div>
            <div className="min-w-0 flex-1 space-y-1.5">
              <Input
                placeholder="ID number"
                value={row.number}
                aria-invalid={!!numberErrors?.[i]}
                onChange={(e) => update(i, { number: e.target.value })}
              />
              {numberErrors?.[i] && <p className="text-xs text-destructive">{numberErrors[i]}</p>}
            </div>
            <Button
              type="button"
              variant="ghost"
              size="icon-sm"
              className="sm:mt-1"
              onClick={() => onIdsChange(ids.filter((_, index) => index !== i))}
              aria-label={`Remove ID ${i + 1}`}
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
        disabled={ids.length >= MAX_OTHER_IDS}
        onClick={() => onIdsChange([...ids, { type: "", number: "" }])}
      >
        <Plus />
        Add ID
      </Button>
    </div>
  );
}
