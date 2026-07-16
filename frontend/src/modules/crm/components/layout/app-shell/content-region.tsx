import { type ReactNode } from "react";
import { cn } from "@/modules/crm/lib/utils";

type ContentRegionProps = {
  header: ReactNode;
  children: ReactNode;
  className?: string;
};

export function ContentRegion({ header, children, className }: ContentRegionProps) {
  return (
    <div className={cn("flex flex-1 flex-col overflow-y-auto bg-background", className)}>
      {header}
      <main className="flex-1 px-4 pb-8 pt-6 lg:px-6">{children}</main>
    </div>
  );
}
