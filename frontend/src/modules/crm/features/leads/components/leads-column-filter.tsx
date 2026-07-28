"use client";

import type { ReactNode } from "react";
import { ListFilter } from "lucide-react";
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/modules/crm/components/ui/dropdown-menu";
import { cn } from "@/modules/crm/lib/utils";

export type ColumnFilterOption = {
  value: string;
  /** Rendered as-is — callers pass the column's own chip so labels/colours can never drift. */
  label: ReactNode;
};

type Props = {
  /** Column name, used for the accessible label (the trigger itself is an icon). */
  column: string;
  options: ColumnFilterOption[];
  /** Currently active values ([] = no filter, i.e. "All"). */
  selected: string[];
  onChange: (values: string[]) => void;
  /**
   * Multi-select emits several values (the backend takes a comma-separated list); single-select
   * emits at most one. Driven by what the API accepts for that column, not by preference.
   */
  multiple?: boolean;
};

/**
 * Filter control for a Leads table column header. Built on the shared DropdownMenu primitives — the
 * same ones the toolbar's Sort menu uses — so it inherits the CRM's existing menu styling, keyboard
 * handling and portal behaviour rather than introducing a new dropdown.
 *
 * It renders options but never defines them: the caller supplies values sourced from the backend
 * whitelist (see LEAD_STATUS_VALUES / LEAD_PRIORITY_VALUES) and supplies each label as the column's
 * existing chip component, so this control cannot introduce a value or a label of its own.
 */
export function LeadsColumnFilter({ column, options, selected, onChange, multiple = false }: Props) {
  const active = selected.length > 0;

  const toggle = (value: string) => {
    if (!multiple) {
      onChange([value]);
      return;
    }
    onChange(
      selected.includes(value) ? selected.filter((v) => v !== value) : [...selected, value],
    );
  };

  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        // Header cells are uppercase/tracked; the trigger is a plain icon button so the column
        // label keeps its existing typography. Highlighted only while a filter is applied.
        className={cn(
          "ml-1.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded transition-colors",
          "hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
          active ? "text-primary-accent" : "text-muted-foreground",
        )}
        aria-label={active ? `Filter by ${column} (active)` : `Filter by ${column}`}
      >
        <ListFilter className="h-3.5 w-3.5" />
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="w-48">
        {multiple ? (
          <>
            {options.map((option) => (
              <DropdownMenuCheckboxItem
                key={option.value}
                checked={selected.includes(option.value)}
                // Keep the menu open while building up a multi-value filter.
                onSelect={(event) => event.preventDefault()}
                onCheckedChange={() => toggle(option.value)}
              >
                {option.label}
              </DropdownMenuCheckboxItem>
            ))}
            <DropdownMenuSeparator />
            <DropdownMenuCheckboxItem
              checked={!active}
              onCheckedChange={() => onChange([])}
              disabled={!active}
            >
              All
            </DropdownMenuCheckboxItem>
          </>
        ) : (
          <DropdownMenuRadioGroup
            value={selected[0] ?? ""}
            onValueChange={(value) => (value ? toggle(value) : onChange([]))}
          >
            {options.map((option) => (
              <DropdownMenuRadioItem key={option.value} value={option.value}>
                {option.label}
              </DropdownMenuRadioItem>
            ))}
            <DropdownMenuSeparator />
            <DropdownMenuRadioItem value="">All</DropdownMenuRadioItem>
          </DropdownMenuRadioGroup>
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
