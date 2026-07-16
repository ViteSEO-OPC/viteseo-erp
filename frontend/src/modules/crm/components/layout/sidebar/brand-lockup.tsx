import { Handshake } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";

type BrandLockupProps = {
  collapsed?: boolean;
};

/**
 * CRM brand mark — a lucide icon in a brand chip (matches the platform's other
 * module sidebars and needs no external asset). The "Zerem" wordmark beside it
 * provides the accessible name.
 */
function BrandMark({ className }: { className?: string }) {
  return (
    <span
      className={cn(
        "flex items-center justify-center rounded-lg bg-brand text-brand-foreground",
        className,
      )}
    >
      <Handshake className="h-5 w-5" />
    </span>
  );
}

export function BrandLockup({ collapsed }: BrandLockupProps) {
  if (collapsed) {
    return (
      <div className="flex h-12 items-center justify-center">
        <BrandMark className="size-8" />
      </div>
    );
  }

  return (
    <div className="flex items-center gap-2.5 px-4 py-3.5">
      <BrandMark className="size-8 shrink-0" />
      <span className="font-primary text-xl font-extrabold tracking-tight text-white">
        Zerem
      </span>
    </div>
  );
}
