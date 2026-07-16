import type { ReactNode } from "react";

type Props = {
  left: ReactNode;
  right: ReactNode;
};

export function DashboardSplitRow({ left, right }: Props) {
  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-[7fr_5fr]">
      <div>{left}</div>
      <div>{right}</div>
    </div>
  );
}
