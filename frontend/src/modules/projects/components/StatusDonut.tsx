"use client";

/** Shared status colours for donut/legend across project views. */
export const STATUS_COLORS = {
  done: "#4fdbd7", // brand cyan
  in_progress: "#6366f1", // indigo
  todo: "#141414", // ink
} as const;

export interface StatusCounts {
  todo: number;
  in_progress: number;
  done: number;
}

/** Segmented donut ring rendered from task status counts. */
export function StatusDonut({
  counts,
  size = 180,
  stroke = 22,
}: {
  counts: StatusCounts;
  size?: number;
  stroke?: number;
}) {
  const segments = [
    { value: counts.done, color: STATUS_COLORS.done },
    { value: counts.in_progress, color: STATUS_COLORS.in_progress },
    { value: counts.todo, color: STATUS_COLORS.todo },
  ];
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const total = segments.reduce((s, x) => s + x.value, 0) || 1;

  // Precompute each arc's length + cumulative offset immutably.
  const arcs = segments.reduce<
    { len: number; offset: number; color: string }[]
  >((acc, s) => {
    const prev = acc[acc.length - 1];
    const offset = prev ? prev.offset + prev.len : 0;
    acc.push({ len: (s.value / total) * c, offset, color: s.color });
    return acc;
  }, []);

  return (
    <svg
      width={size}
      height={size}
      viewBox={`0 0 ${size} ${size}`}
      className="-rotate-90"
    >
      <circle
        cx={size / 2}
        cy={size / 2}
        r={r}
        fill="none"
        stroke="#ececeb"
        strokeWidth={stroke}
      />
      {arcs.map((arc, i) => (
        <circle
          key={i}
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          stroke={arc.color}
          strokeWidth={stroke}
          strokeDasharray={`${arc.len} ${c - arc.len}`}
          strokeDashoffset={-arc.offset}
          strokeLinecap="butt"
        />
      ))}
    </svg>
  );
}
