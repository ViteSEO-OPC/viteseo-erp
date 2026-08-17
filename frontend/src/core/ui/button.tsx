import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { Slot } from "radix-ui"

import { cn } from "@/core/lib/utils"

/**
 * The button ladder. Four tiers, in descending emphasis — pick by how much
 * weight the action deserves, never by which colour looks right:
 *
 *   default        The primary. Brand cyan fill (`--primary`, #4fdbd7) with
 *                  dark-teal ink (`--primary-foreground`). One per surface — a
 *                  page, a modal footer, or a single table row ("Approve",
 *                  "Restore" are each their row's primary).
 *   brand-outline  Brand secondary. A real action that isn't THE action —
 *                  "Import Tasks" beside "Add Task", "Add Member", "Add
 *                  Subtask", "Create Group".
 *   outline        Neutral secondary. Cancel, Filters, view switches — things
 *                  that shouldn't carry brand colour at all.
 *   ghost / link   Quiet. Dismissals and inline navigation.
 *
 * Destructive comes in the same two weights. `destructive` is the filled red of
 * a confirmation dialog's final button; `destructive-outline` is the same intent
 * at secondary weight — "Reject" sitting beside "Approve" in a review row, where
 * a filled red would out-shout the primary it is paired with.
 *
 * `brand-outline` draws in `--color-brand-strong` (#0f766e), NOT in `--brand`.
 * The bright cyan sits at ~1.9:1 on white, so a cyan border with a cyan label is
 * unreadable; brand-strong is that same brand pulled to text contrast (~4.8:1).
 * Cyan is for fills, brand-strong is for strokes and labels — that split is the
 * whole reason both tokens exist, and mixing them (a cyan border with a
 * brand-strong label) was the single most-copied mistake this variant replaces.
 *
 * Every tier shares the base size, radius and `font-medium`, and each outlined
 * tier is a 1px stroke on `bg-surface`, so a primary and a secondary sitting
 * side by side are the same object at two emphases rather than two
 * differently-built controls. Set colour here, never at the call site: a
 * `className` that repaints a button is how the module ended up with three
 * primaries in the first place.
 */
const buttonVariants = cva(
  "inline-flex shrink-0 items-center justify-center gap-2 rounded-md text-sm font-medium whitespace-nowrap transition-all outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
  {
    variants: {
      variant: {
        default: "bg-primary text-primary-foreground hover:bg-primary/90",
        destructive:
          "bg-destructive text-white hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:bg-destructive/60 dark:focus-visible:ring-destructive/40",
        "destructive-outline":
          "border border-destructive/40 bg-surface text-destructive hover:bg-destructive/5 focus-visible:ring-destructive/20",
        outline:
          "border border-line bg-surface hover:bg-accent hover:text-accent-foreground dark:border-input dark:bg-input/30 dark:hover:bg-input/50",
        "brand-outline":
          "border border-brand-strong bg-surface text-brand-strong hover:bg-brand-strong/5",
        secondary:
          "bg-secondary text-secondary-foreground hover:bg-secondary/80",
        ghost:
          "hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50",
        // brand-strong, not `--primary`: a link is read, not filled, and the
        // cyan primary sits at ~1.9:1 on white. Same reasoning as brand-outline.
        link: "text-brand-strong underline-offset-4 hover:text-brand-strong-hover hover:underline",
      },
      // Each size carries its own icon step, from the scale in core/ui/icons.tsx:
      // the `xs` sizes set `text-xs` so their icons are 14px, and every other
      // size inherits the base `text-sm` and its 16px icon. Call sites should
      // pass the icon unclassed and let this decide — `className="h-4 w-4"` on
      // an icon inside an `xs` button is a 16px glyph fighting a 12px label.
      size: {
        default: "h-9 px-4 py-2 has-[>svg]:px-3",
        xs: "h-6 gap-1 rounded-md px-2 text-xs has-[>svg]:px-1.5 [&_svg:not([class*='size-'])]:size-3.5",
        sm: "h-8 gap-1.5 rounded-md px-3 has-[>svg]:px-2.5",
        lg: "h-10 rounded-md px-6 has-[>svg]:px-4",
        icon: "size-9",
        "icon-xs": "size-6 rounded-md [&_svg:not([class*='size-'])]:size-3.5",
        "icon-sm": "size-8",
        "icon-lg": "size-10",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Button({
  className,
  variant = "default",
  size = "default",
  asChild = false,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
  }) {
  const Comp = asChild ? Slot.Root : "button"

  return (
    <Comp
      data-slot="button"
      data-variant={variant}
      data-size={size}
      className={cn(buttonVariants({ variant, size, className }))}
      {...props}
    />
  )
}

export type ButtonProps = React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & { asChild?: boolean }

export { Button, buttonVariants }
