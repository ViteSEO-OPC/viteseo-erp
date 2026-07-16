"use client"

import { cn } from "@/core/lib/utils"
import { Tabs as TabsRoot, TabsList, TabsTrigger } from "@/core/ui/tabs"

/**
 * Underline tab bar built on the shadcn/Radix Tabs primitive, preserving the
 * original `{ tabs, active, onChange }` API. Tab panels are rendered by the
 * parent based on `active` (this component is the bar only).
 */
export function Tabs({
  tabs,
  active,
  onChange,
  className,
}: {
  tabs: { key: string; label: string }[]
  active: string
  onChange: (key: string) => void
  className?: string
}) {
  return (
    <TabsRoot value={active} onValueChange={onChange} className={className}>
      <TabsList
        variant="line"
        className={cn("w-full justify-start gap-6 rounded-none border-b border-line bg-transparent p-0")}
      >
        {tabs.map((t) => (
          <TabsTrigger
            key={t.key}
            value={t.key}
            className="flex-none px-1 pb-3 font-bold data-[state=active]:text-ink"
          >
            {t.label}
          </TabsTrigger>
        ))}
      </TabsList>
    </TabsRoot>
  )
}
