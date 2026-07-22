import type { ReactNode } from "react";

type Props = {
  left: ReactNode;
  right: ReactNode;
};

export function DashboardSplitRow({ left, right }: Props) {
  return (
    // `min-w-0` is load-bearing: grid items default to `min-width: auto`, so the leads table's
    // intrinsic width overrode the 7fr/5fr ratio and squeezed the right column to about half its
    // share. Without it the row does not match the approved proportions.
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-[7fr_5fr]">
      <div className="min-w-0">{left}</div>
      <div className="min-w-0">{right}</div>
    </div>
  );
}
