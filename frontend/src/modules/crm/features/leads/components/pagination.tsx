"use client";

import { ChevronLeft, ChevronRight } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";

type PaginationProps = {
  page: number;
  totalPages: number;
  total: number;
  onPageChange: (page: number) => void;
};

export function Pagination({
  page,
  totalPages,
  total,
  onPageChange,
}: PaginationProps) {
  const from = page * 10 + 1;
  const to = Math.min((page + 1) * 10, total);

  return (
    <div className="flex items-center justify-between px-1 py-3">
      <p className="text-sm text-muted-foreground">
        Showing <span className="font-medium text-foreground">{from}</span>–
        <span className="font-medium text-foreground">{to}</span> of{" "}
        <span className="font-medium text-foreground">{total}</span>
      </p>

      <div className="flex items-center gap-1">
        <button
          type="button"
          onClick={() => onPageChange(page - 1)}
          disabled={page === 0}
          className={cn(
            "flex h-8 w-8 items-center justify-center rounded-md text-sm transition-colors",
            "text-muted-foreground hover:bg-muted hover:text-foreground",
            "disabled:pointer-events-none disabled:opacity-40",
            "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
          )}
          aria-label="Previous page"
        >
          <ChevronLeft className="h-4 w-4" />
        </button>

        {Array.from({ length: totalPages }, (_, i) => (
          <button
            key={i}
            type="button"
            onClick={() => onPageChange(i)}
            className={cn(
              "flex h-8 min-w-8 items-center justify-center rounded-md px-2 text-sm transition-colors",
              "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
              i === page
                ? "bg-primary text-primary-foreground"
                : "text-muted-foreground hover:bg-muted hover:text-foreground",
            )}
            aria-label={`Page ${i + 1}`}
            aria-current={i === page ? "page" : undefined}
          >
            {i + 1}
          </button>
        ))}

        <button
          type="button"
          onClick={() => onPageChange(page + 1)}
          disabled={page >= totalPages - 1}
          className={cn(
            "flex h-8 w-8 items-center justify-center rounded-md text-sm transition-colors",
            "text-muted-foreground hover:bg-muted hover:text-foreground",
            "disabled:pointer-events-none disabled:opacity-40",
            "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
          )}
          aria-label="Next page"
        >
          <ChevronRight className="h-4 w-4" />
        </button>
      </div>
    </div>
  );
}
