"use client";

import { ChartCard } from "@/modules/crm/components/shared/chart-card/chart-card";
import {
  useReportsAnalytics,
  type OutreachChannel,
} from "@/modules/crm/features/reports/hooks/use-reports-analytics";

/**
 * Outreach progress by channel — LIVE. Email vs Cold calling, each split into completed / in-progress /
 * remaining from the real lead fields (email_outreach, cold_calling — mapping in the controller
 * docblock). One stacked bar answers two questions at once: progress made and workload remaining.
 */
// Brand palette from the mockup: Completed = deep brand teal, In progress = green, Not started = light gray.
const COMPLETED_COLOR = "var(--chart-teal-dark)";
const IN_PROGRESS_COLOR = "#22C55E";

const LEGEND = [
  { label: "Completed", color: COMPLETED_COLOR },
  { label: "In progress", color: IN_PROGRESS_COLOR },
  { label: "Not started", color: "var(--muted)" },
] as const;

/** Five evenly-spaced axis ticks 0 → total, matching the mockup's baseline scale under the bars. */
function AxisTicks({ total }: { total: number }) {
  const ticks = [0, 0.25, 0.5, 0.75, 1].map((f) => Math.round(total * f));
  return (
    <div className="flex justify-between pt-1 text-[10px] tabular-nums text-muted-foreground">
      {ticks.map((t, i) => (
        <span key={i}>{t}</span>
      ))}
    </div>
  );
}

function ChannelRow({ name, channel }: { name: string; channel: OutreachChannel }) {
  const total = channel.total || 1;
  const pct = channel.total > 0 ? Math.round((channel.completed / channel.total) * 100) : 0;
  const seg = (value: number) => `${(value / total) * 100}%`;

  return (
    <div>
      <div className="flex items-center justify-between gap-2 text-sm">
        <span className="font-semibold text-card-foreground">{name}</span>
        <span className="text-xs text-muted-foreground">
          {channel.completed} of {channel.total} completed · {pct}%
        </span>
      </div>
      <div className="mt-2 flex h-7 w-full overflow-hidden rounded-lg bg-secondary">
        {channel.completed > 0 && (
          <div
            className="flex items-center justify-center text-[11px] font-semibold text-white"
            style={{ width: seg(channel.completed), backgroundColor: COMPLETED_COLOR }}
          >
            {channel.completed}
          </div>
        )}
        {channel.inProgress > 0 && (
          <div
            className="flex items-center justify-center text-[11px] font-semibold text-white"
            style={{ width: seg(channel.inProgress), backgroundColor: IN_PROGRESS_COLOR }}
          >
            {channel.inProgress}
          </div>
        )}
        {channel.notStarted > 0 && (
          <div
            className="flex items-center justify-center bg-muted text-[11px] font-semibold text-muted-foreground"
            style={{ width: seg(channel.notStarted) }}
          >
            {channel.notStarted}
          </div>
        )}
      </div>
    </div>
  );
}

export function OutreachByChannelCard({ className }: { className?: string }) {
  const { data, isLoading, isError } = useReportsAnalytics();
  const email = data?.outreachByChannel.email;
  const call = data?.outreachByChannel.call;
  const empty = !isLoading && (email?.total ?? 0) === 0 && (call?.total ?? 0) === 0;
  // Axis scale must span the larger channel; `??` would pick email's 0 over a non-zero call total.
  const axisTotal = Math.max(email?.total ?? 0, call?.total ?? 0);

  return (
    <ChartCard
      title="Outreach progress by channel"
      subtitle="Email vs cold calling — done, doing, remaining"
      className={className}
      isLoading={isLoading}
      isError={isError}
      isEmpty={empty}
      filter={
        <div className="flex items-center gap-3">
          {LEGEND.map((l) => (
            <span key={l.label} className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
              <span
                className="h-2.5 w-2.5 rounded-sm"
                style={{ backgroundColor: l.color }}
                aria-hidden
              />
              {l.label}
            </span>
          ))}
        </div>
      }
    >
      <div className="flex flex-col gap-5 pt-1">
        {email && <ChannelRow name="Email outreach" channel={email} />}
        {call && <ChannelRow name="Cold calling" channel={call} />}
        {axisTotal > 0 ? <AxisTicks total={axisTotal} /> : null}
        <p className="text-xs text-muted-foreground">
          One figure, two answers: progress made and workload remaining per channel.
        </p>
      </div>
    </ChartCard>
  );
}
