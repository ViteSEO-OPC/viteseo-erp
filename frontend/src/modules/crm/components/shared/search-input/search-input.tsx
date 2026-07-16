"use client";

import { Search } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";

type SearchInputProps = {
  className?: string;
  value?: string;
  onChange?: (value: string) => void;
  onSubmit?: (value: string) => void;
  placeholder?: string;
  "aria-label"?: string;
};

export function SearchInput({
  className,
  value,
  onChange,
  onSubmit,
  placeholder = "Search leads, companies, or contacts…",
  "aria-label": ariaLabel,
}: SearchInputProps) {
  return (
    <div className={cn("relative", className)}>
      <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
      <input
        type="search"
        value={value}
        onChange={onChange ? (e) => onChange(e.target.value) : undefined}
        onKeyDown={
          onSubmit
            ? (e) => {
                if (e.key === "Enter") {
                  e.preventDefault();
                  onSubmit(e.currentTarget.value);
                }
              }
            : undefined
        }
        placeholder={placeholder}
        aria-label={ariaLabel ?? placeholder}
        className={cn(
          "h-9 w-full rounded-lg border border-border bg-card pl-9 pr-3 text-sm text-foreground",
          "placeholder:text-muted-foreground",
          "focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-0",
          "disabled:cursor-not-allowed disabled:opacity-50",
        )}
      />
    </div>
  );
}
