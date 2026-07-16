import * as React from "react"

import { cn } from "@/core/lib/utils"
import {
  Avatar as AvatarRoot,
  AvatarFallback,
  AvatarImage,
} from "@/core/ui/avatar"

const PALETTE = ["#4fdbd7", "#6366f1", "#f59e0b", "#ef4444", "#10b981", "#8b5cf6"]

function initials(name: string): string {
  return name
    .split(" ")
    .map((part) => part[0])
    .filter(Boolean)
    .slice(0, 2)
    .join("")
    .toUpperCase()
}

function colorFor(name: string): string {
  let hash = 0
  for (let i = 0; i < name.length; i += 1) hash = name.charCodeAt(i) + ((hash << 5) - hash)
  return PALETTE[Math.abs(hash) % PALETTE.length]
}

export interface AvatarProps {
  name: string
  src?: string | null
  size?: number
  className?: string
}

/**
 * Name-based avatar built on the shadcn/Radix Avatar primitive. Renders an
 * image when `src` is provided, otherwise a deterministic colored fallback with
 * the person's initials.
 */
export function Avatar({ name, src, size = 32, className = "" }: AvatarProps) {
  const style = { width: size, height: size } as React.CSSProperties

  return (
    <AvatarRoot
      style={style}
      title={name}
      className={cn("ring-2 ring-surface", className)}
    >
      {src ? <AvatarImage src={src} alt={name} className="object-cover" /> : null}
      <AvatarFallback
        style={{ backgroundColor: colorFor(name), fontSize: size * 0.38 }}
        className="font-bold text-white"
      >
        {initials(name)}
      </AvatarFallback>
    </AvatarRoot>
  )
}

export function AvatarStack({
  people,
  max = 4,
  size = 32,
}: {
  people: { name: string; src?: string | null }[]
  max?: number
  size?: number
}) {
  const shown = people.slice(0, max)
  const extra = people.length - shown.length

  return (
    <div className="flex items-center">
      <div className="flex -space-x-2">
        {shown.map((p, i) => (
          <Avatar key={`${p.name}-${i}`} name={p.name} src={p.src} size={size} />
        ))}
      </div>
      {extra > 0 && (
        <span className="ml-1 text-xs font-semibold text-ink-soft">+{extra}</span>
      )}
    </div>
  )
}
