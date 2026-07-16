import * as React from "react"
import { ChevronDownIcon } from "lucide-react"

import { cn } from "@/core/lib/utils"

/**
 * Native `<select>` styled with the shadcn token set. Kept as the drop-in
 * `Select` so react-hook-form `register`, `value`/`onChange`, and `<option>`
 * children keep working. For richer, portal-based menus use the Radix
 * `SelectRoot`/`SelectTrigger`/`SelectItem` compositional API instead.
 */
export const NativeSelect = React.forwardRef<
  HTMLSelectElement,
  React.SelectHTMLAttributes<HTMLSelectElement>
>(function NativeSelect({ className, children, ...props }, ref) {
  return (
    <div className="relative">
      <select
        ref={ref}
        data-slot="native-select"
        className={cn(
          "h-9 w-full appearance-none rounded-md border border-input bg-transparent px-3 pr-8 text-sm font-medium text-foreground shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20",
          className
        )}
        {...props}
      >
        {children}
      </select>
      <ChevronDownIcon className="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 opacity-50" />
    </div>
  )
})
