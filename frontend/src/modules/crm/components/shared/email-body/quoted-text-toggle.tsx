"use client";

import { Ellipsis } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { cn } from "@/modules/crm/lib/utils";

type Props = {
  expanded: boolean;
  onToggle: () => void;
  /** Id of the element the toggle shows and hides. */
  controls?: string;
  className?: string;
};

/** "Show quoted text" — shared by the plain-text and HTML renderers so both read the same. */
export function QuotedTextToggle({ expanded, onToggle, controls, className }: Props) {
  return (
    <Button
      type="button"
      variant="ghost"
      size="xs"
      aria-expanded={expanded}
      aria-controls={controls}
      onClick={onToggle}
      className={cn("whitespace-normal text-muted-foreground", className)}
    >
      <Ellipsis aria-hidden />
      {expanded ? "Hide quoted text" : "Show quoted text"}
    </Button>
  );
}
