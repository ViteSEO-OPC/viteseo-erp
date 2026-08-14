"use client";

import { useState } from "react";
import { Avatar, ChevronDownIcon } from "@/core/ui";

export interface FilterOption<T extends string | number = string | number> {
  id: T;
  name: string;
  avatar?: string | null;
  color?: string;
}

/**
 * A dropdown that lets you pick one or more options (projects, members, …).
 * Ids may be UUID strings (projects) or numeric (members). Empty selection means "all".
 */
export function MultiSelectFilter<T extends string | number>({
  label,
  options,
  selectedIds,
  onChange,
  withAvatars,
}: {
  label: string;
  options: FilterOption<T>[];
  selectedIds: T[];
  onChange: (ids: T[]) => void;
  withAvatars?: boolean;
}): React.ReactElement {
  const [open, setOpen] = useState(false);
  const selected = new Set<T>(selectedIds);

  const toggle = (id: T): void => {
    const next = new Set(selected);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    onChange([...next]);
  };

  const count = selectedIds.length;
  const summary = count === 0 ? `All ${label}` : `${count} ${label}`;

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className={`flex h-10 items-center gap-2 rounded-lg border bg-surface px-3 text-sm font-bold transition-colors ${
          count > 0 ? 'border-brand text-ink' : 'border-line text-ink-soft hover:text-ink'
        }`}
      >
        {summary}
        <ChevronDownIcon className="h-4 w-4" />
      </button>

      {open && (
        <>
          <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} aria-hidden />
          <div className="absolute left-0 z-20 mt-2 max-h-72 w-64 overflow-y-auto rounded-xl border border-line bg-surface p-2 shadow-lg">
            <div className="flex items-center justify-between px-2 py-1">
              <span className="text-xs font-bold uppercase tracking-wide text-ink-faint">{label}</span>
              {count > 0 && (
                <button
                  type="button"
                  onClick={() => onChange([])}
                  className="text-xs font-bold text-brand hover:underline"
                >
                  Clear
                </button>
              )}
            </div>
            {options.map((o) => {
              const isOn = selected.has(o.id);
              return (
                <button
                  key={o.id}
                  type="button"
                  onClick={() => toggle(o.id)}
                  className={`flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-sm transition-colors ${
                    isOn ? 'bg-brand-soft' : 'hover:bg-black/5'
                  }`}
                >
                  <span
                    className={`flex h-4 w-4 shrink-0 items-center justify-center rounded border text-2xs ${
                      isOn ? 'border-brand bg-brand text-ink' : 'border-line'
                    }`}
                  >
                    {isOn && '✓'}
                  </span>
                  {withAvatars && <Avatar name={o.name} src={o.avatar} size={22} />}
                  {o.color && (
                    <span className="h-3 w-3 shrink-0 rounded-full" style={{ backgroundColor: o.color }} />
                  )}
                  <span className="truncate font-semibold text-ink">{o.name}</span>
                </button>
              );
            })}
            {options.length === 0 && (
              <p className="px-2 py-3 text-center text-sm text-ink-soft">No {label.toLowerCase()}.</p>
            )}
          </div>
        </>
      )}
    </div>
  );
}
