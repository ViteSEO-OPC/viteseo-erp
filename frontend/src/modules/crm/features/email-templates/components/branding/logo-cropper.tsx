"use client";

import { useEffect, useRef, useState } from "react";
import { cn } from "@/modules/crm/lib/utils";
import {
  moveCrop,
  resizeCrop,
  type CropBounds,
  type CropHandle,
  type CropRect,
} from "@/modules/crm/features/email-templates/lib/logo-crop";

/** The largest the image is shown while cropping. Small SVGs scale up; they are vector. */
const MAX_WIDTH = 520;
const MAX_HEIGHT = 300;

const HANDLES: readonly { handle: CropHandle; className: string }[] = [
  { handle: "nw", className: "-top-1.5 -left-1.5 cursor-nwse-resize" },
  { handle: "n", className: "-top-1.5 left-1/2 -translate-x-1/2 cursor-ns-resize" },
  { handle: "ne", className: "-top-1.5 -right-1.5 cursor-nesw-resize" },
  { handle: "e", className: "top-1/2 -right-1.5 -translate-y-1/2 cursor-ew-resize" },
  { handle: "se", className: "-right-1.5 -bottom-1.5 cursor-nwse-resize" },
  { handle: "s", className: "-bottom-1.5 left-1/2 -translate-x-1/2 cursor-ns-resize" },
  { handle: "sw", className: "-bottom-1.5 -left-1.5 cursor-nesw-resize" },
  { handle: "w", className: "top-1/2 -left-1.5 -translate-y-1/2 cursor-ew-resize" },
];

/** A transparency checkerboard from the theme's own tokens, so a transparent logo reads as transparent. */
export const CHECKERBOARD =
  "[background-image:repeating-conic-gradient(var(--color-muted)_0%_25%,var(--color-card)_0%_50%)] [background-size:16px_16px]";

type Props = {
  src: string;
  alt: string;
  bounds: CropBounds;
  crop: CropRect;
  minSize: CropBounds;
  onChange: (crop: CropRect) => void;
  disabled?: boolean;
};

/**
 * Crop and position the logo: drag inside the frame to move it, drag an edge or corner to resize it. The arrow
 * keys move the focused frame (Shift for bigger steps) and Alt with the arrows resizes it from its bottom-right
 * corner. Everything outside the frame is dimmed; the checkerboard shows transparency.
 *
 * Coordinates stay in the image's own units (see lib/logo-crop), so the rectangle is exactly what the server crops.
 */
export function LogoCropper({ src, alt, bounds, crop, minSize, onChange, disabled = false }: Props) {
  // The image is scaled to the width actually available, so the drawn frame, the handles and the drag arithmetic
  // all use the size the image is really shown at — in a narrow dialog column as much as a wide one.
  const containerRef = useRef<HTMLDivElement>(null);
  const [available, setAvailable] = useState(MAX_WIDTH);
  useEffect(() => {
    const container = containerRef.current;
    if (!container) return;
    const observer = new ResizeObserver(([entry]) => setAvailable(Math.max(80, Math.floor(entry.contentRect.width))));
    observer.observe(container);
    return () => observer.disconnect();
  }, []);
  const scale = Math.min(Math.min(MAX_WIDTH, available) / bounds.width, MAX_HEIGHT / bounds.height);
  const drag = useRef<{ pointerId: number; startX: number; startY: number; start: CropRect; handle: CropHandle | "move" } | null>(null);

  function begin(event: React.PointerEvent<HTMLElement>, handle: CropHandle | "move") {
    if (disabled || event.button !== 0) return;
    event.preventDefault();
    event.stopPropagation();
    event.currentTarget.setPointerCapture(event.pointerId);
    drag.current = { pointerId: event.pointerId, startX: event.clientX, startY: event.clientY, start: crop, handle };
  }

  function update(event: React.PointerEvent<HTMLElement>) {
    const current = drag.current;
    if (!current || current.pointerId !== event.pointerId) return;
    const dx = (event.clientX - current.startX) / scale;
    const dy = (event.clientY - current.startY) / scale;
    onChange(
      current.handle === "move"
        ? moveCrop(current.start, dx, dy, bounds)
        : resizeCrop(current.start, current.handle, dx, dy, bounds, minSize),
    );
  }

  function end(event: React.PointerEvent<HTMLElement>) {
    if (drag.current?.pointerId === event.pointerId) drag.current = null;
  }

  function onKeyDown(event: React.KeyboardEvent<HTMLDivElement>) {
    const step = (Math.max(bounds.width, bounds.height) / 100) * (event.shiftKey ? 5 : 1);
    const delta = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[event.key];
    if (!delta || disabled) return;
    event.preventDefault();
    onChange(event.altKey ? resizeCrop(crop, "se", delta[0], delta[1], bounds, minSize) : moveCrop(crop, delta[0], delta[1], bounds));
  }

  const pointer = { onPointerMove: update, onPointerUp: end, onPointerCancel: end };
  const frameBox = { left: crop.x * scale, top: crop.y * scale, width: crop.width * scale, height: crop.height * scale };

  return (
    <div ref={containerRef} className="flex w-full min-w-0 justify-center rounded-lg border border-border bg-muted p-4">
      <div className="relative shrink-0 touch-none select-none" style={{ width: bounds.width * scale, height: bounds.height * scale }}>
        {/*
          Only the image and the dimming are clipped to the image. The frame and its handles sit above, unclipped, so
          a handle on the image's edge — where every handle starts — is whole and can be grabbed at its centre.
        */}
        <div className={cn("pointer-events-none absolute inset-0 overflow-hidden", CHECKERBOARD)}>
          {/* A local blob: URL of the chosen file (or the server-sanitized SVG), which next/image cannot load. */}
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={src} alt={alt} draggable={false} className="absolute inset-0 size-full max-w-none" />
          <div aria-hidden className="absolute shadow-[0_0_0_9999px_rgb(0_0_0/0.5)]" style={frameBox} />
        </div>
        <div
          role="group"
          tabIndex={disabled ? -1 : 0}
          aria-label="Crop frame. Drag to move, drag an edge or corner to resize. Arrow keys move it; Alt and the arrow keys resize it."
          data-crop-frame=""
          onKeyDown={onKeyDown}
          onPointerDown={(event) => begin(event, "move")}
          {...pointer}
          className={cn("absolute outline-2 outline-white focus-visible:outline-ring", disabled ? "cursor-not-allowed" : "cursor-move")}
          style={frameBox}
        >
          {HANDLES.map(({ handle, className }) => (
            <span
              key={handle}
              aria-hidden
              data-handle={handle}
              onPointerDown={(event) => begin(event, handle)}
              {...pointer}
              className={cn("absolute size-3 rounded-sm border border-foreground/50 bg-white", className)}
            />
          ))}
        </div>
      </div>
    </div>
  );
}
