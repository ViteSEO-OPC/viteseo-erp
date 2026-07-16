"use client";

import type { ReactNode } from "react";
import { Card, CardContent } from "@/modules/crm/components/ui/card";
import { cn } from "@/modules/crm/lib/utils";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";

type ChartCardProps = {
  title: string;
  subtitle?: string;
  filter?: ReactNode;
  children: ReactNode;
  className?: string;
  isLoading?: boolean;
  isEmpty?: boolean;
  isError?: boolean;
  onRetry?: () => void;
  emptyMessage?: string;
};

export function ChartCard({
  title,
  subtitle,
  filter,
  children,
  className,
  isLoading,
  isEmpty,
  isError,
  onRetry,
  emptyMessage = "No data available",
}: ChartCardProps) {
  return (
    <Card className={cn("border-border shadow-card", className)}>
      <CardContent className="flex flex-col gap-3 pt-4">
        <div className="flex items-start justify-between gap-2">
          <div>
            <h3 className="text-sm font-bold text-gray-900">{title}</h3>
            {subtitle && (
              <p className="text-xs text-gray-500">{subtitle}</p>
            )}
          </div>
          {filter && <div className="shrink-0">{filter}</div>}
        </div>
        {isLoading && (
          <div className="flex items-center justify-center py-8">
            <div className="h-32 w-full animate-pulse rounded-lg bg-gray-100" />
          </div>
        )}
        {isError && <ErrorState message="Failed to load chart" onRetry={onRetry} />}
        {isEmpty && !isLoading && !isError && <EmptyState message={emptyMessage} />}
        {!isLoading && !isError && !isEmpty && <div className="min-h-0">{children}</div>}
      </CardContent>
    </Card>
  );
}
