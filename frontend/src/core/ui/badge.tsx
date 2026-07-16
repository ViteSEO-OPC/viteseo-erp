import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { Slot } from "radix-ui"

import { cn } from "@/core/lib/utils"

/**
 * Brand badge — shadcn cva pattern extended with the Sprintly semantic tones.
 * `tone` maps to the brand status colors; the shadcn `variant` prop is kept for
 * primary/secondary/outline usage when a plain shadcn badge is wanted.
 */
const badgeVariants = cva(
  "inline-flex w-fit shrink-0 items-center justify-center gap-1 overflow-hidden rounded-full border border-transparent px-2.5 py-0.5 text-xs font-bold tracking-wide uppercase whitespace-nowrap transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>svg]:pointer-events-none [&>svg]:size-3",
  {
    variants: {
      tone: {
        accent: "bg-brand-soft text-[#0f8f8b]",
        success: "bg-success-soft text-success",
        warning: "bg-warning-soft text-warning",
        danger: "bg-danger-soft text-danger",
        info: "bg-info-soft text-info",
        neutral: "bg-black/5 text-ink-soft",
      },
    },
    defaultVariants: {
      tone: "neutral",
    },
  }
)

export type BadgeTone = NonNullable<VariantProps<typeof badgeVariants>["tone"]>

export interface BadgeProps
  extends React.ComponentProps<"span">,
    VariantProps<typeof badgeVariants> {
  asChild?: boolean
}

function Badge({ className, tone, asChild = false, ...props }: BadgeProps) {
  const Comp = asChild ? Slot.Root : "span"

  return (
    <Comp
      data-slot="badge"
      data-tone={tone ?? "neutral"}
      className={cn(badgeVariants({ tone }), className)}
      {...props}
    />
  )
}

/** Maps a task/subtask priority to a badge tone. */
export function PriorityBadge({ priority }: { priority: "Low" | "Medium" | "High" }) {
  const tone: BadgeTone =
    priority === "High" ? "danger" : priority === "Medium" ? "warning" : "success"
  return <Badge tone={tone}>{priority}</Badge>
}

export { Badge, badgeVariants }
