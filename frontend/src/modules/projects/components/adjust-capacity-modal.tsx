"use client";

import { useState } from "react";
import { Button, Modal } from "@/core/ui";
import {
  useUpdateWorkloadCapacity,
} from "@/modules/projects/hooks/use-workload";
import type {
  WorkloadBar,
  WorkloadThresholds,
} from "@/modules/projects/types/workload";
import { CATEGORY_COLORS } from "@/modules/projects/components/workload-shared";

/** Config fallbacks — what "Reset to Default" restores. */
const DEFAULTS = { max: 100, light: 20, moderate: 40 };

/**
 * Retune the platform-wide capacity bands.
 *
 * These thresholds decide when a person's summed effort reads Light, Moderate
 * or Heavy — here, on every board, and in the task modal's assignment
 * indicator. The preview counts how the CURRENT team would fall out under the
 * proposed bands, so a PM can see the consequence before applying it rather
 * than discovering it on the chart afterwards.
 */
export function AdjustCapacityModal({
  open,
  onClose,
  thresholds,
  bars,
}: {
  open: boolean;
  onClose: () => void;
  thresholds: WorkloadThresholds;
  /** The people currently charted, for the live preview. */
  bars: WorkloadBar[];
}) {
  const update = useUpdateWorkloadCapacity();

  const [max, setMax] = useState(thresholds.max);
  const [light, setLight] = useState(thresholds.light);
  const [moderate, setModerate] = useState(thresholds.moderate);
  // Applying is a two-step: edit, then confirm what it does to real people.
  // An inline step rather than a nested dialog — a modal on top of a modal is
  // worse to escape from, and the summary needs the room anyway.
  const [stage, setStage] = useState<"edit" | "confirm">("edit");

  // Re-seed from the server whenever the modal is (re)opened, so a cancelled
  // edit never lingers. Done during render, React's recommended pattern.
  const [wasOpen, setWasOpen] = useState(open);
  if (open !== wasOpen) {
    setWasOpen(open);
    if (open) {
      setMax(thresholds.max);
      setLight(thresholds.light);
      setModerate(thresholds.moderate);
      setStage("edit");
    }
  }

  // Keep the bands ordered as the user drags: light < moderate < max. The
  // server enforces this too; clamping here just avoids an impossible slider.
  const setMaxClamped = (next: number) => {
    const m = Math.max(3, Math.min(1000, next));
    setMax(m);
    setModerate((mod) => Math.min(mod, m - 1));
    setLight((l) => Math.min(l, m - 2));
  };
  const setLightClamped = (next: number) => setLight(Math.min(next, moderate - 1));
  const setModerateClamped = (next: number) =>
    setModerate(Math.min(Math.max(next, light + 1), max - 1));

  const preview = {
    light: bars.filter((b) => b.total_effort <= light).length,
    moderate: bars.filter((b) => b.total_effort > light && b.total_effort <= moderate).length,
    heavy: bars.filter((b) => b.total_effort > moderate).length,
  };

  const pct = (n: number) => `${Math.max(0, Math.min(100, (n / max) * 100))}%`;

  /** The band a total would fall in under the PROPOSED thresholds. */
  const proposedLevel = (total: number): "light" | "moderate" | "heavy" =>
    total <= light ? "light" : total <= moderate ? "moderate" : "heavy";

  // Who actually changes band. This is the consequence worth confirming —
  // "are you sure?" on its own tells a reviewer nothing.
  const movers = bars
    .map((b) => ({ name: b.name, from: b.level, to: proposedLevel(b.total_effort) }))
    .filter((m) => m.from !== m.to);

  const unchanged =
    max === thresholds.max &&
    light === thresholds.light &&
    moderate === thresholds.moderate;

  const apply = () =>
    update.mutate(
      { max_capacity: max, light_max: light, moderate_max: moderate },
      { onSuccess: () => onClose() },
    );

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Workload Capacity"
      width={640}
      footer={
        stage === "edit" ? (
          <div className="flex w-full flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
            <button
              type="button"
              onClick={() => {
                setMax(DEFAULTS.max);
                setLight(DEFAULTS.light);
                setModerate(DEFAULTS.moderate);
              }}
              className="text-sm font-semibold text-ink-soft underline underline-offset-2 hover:text-ink"
            >
              Reset to Default
            </button>
            <div className="flex gap-3">
              <Button variant="outline" className="flex-1 sm:flex-none" onClick={onClose}>
                Cancel
              </Button>
              <Button
                className="flex-1 sm:flex-none"
                disabled={unchanged}
                onClick={() => setStage("confirm")}
              >
                Apply Thresholds
              </Button>
            </div>
          </div>
        ) : (
          <div className="flex w-full flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              className="flex-1 sm:flex-none"
              onClick={() => setStage("edit")}
              disabled={update.isPending}
            >
              Back
            </Button>
            <Button
              className="flex-1 sm:flex-none"
              onClick={apply}
              disabled={update.isPending}
            >
              {update.isPending ? "Applying…" : "Yes, apply thresholds"}
            </Button>
          </div>
        )
      }
    >
      {stage === "confirm" ? (
        <ConfirmStep
          from={thresholds}
          to={{ light, moderate, max }}
          movers={movers}
          memberCount={bars.length}
          error={update.isError}
        />
      ) : (
      <>
      <p className="-mt-1 mb-4 text-sm text-ink-soft">
        Sets when a member&apos;s total effort counts as Light, Moderate or Heavy —
        everywhere in Sprintly.
      </p>

      {/* Maximum capacity — the top of the range the bands are drawn against. */}
      <div className="flex flex-col gap-3 rounded-xl border border-line px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
        <div className="min-w-0">
          <p className="text-sm font-bold text-ink">Maximum Capacity</p>
          <p className="text-xs text-ink-soft">
            Top of the effort range — bands rescale with it
          </p>
        </div>
        {/* Stretches to the full row on a phone so the stepper stays thumb-sized
            instead of being crushed against the label. */}
        <div className="flex shrink-0 items-center gap-2">
          <button
            type="button"
            aria-label="Decrease maximum capacity"
            onClick={() => setMaxClamped(max - 5)}
            className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-line text-lg text-ink-soft hover:border-brand hover:text-ink sm:h-9 sm:w-9 sm:text-base"
          >
            −
          </button>
          <input
            type="number"
            inputMode="numeric"
            value={max}
            aria-label="Maximum capacity"
            onChange={(e) => setMaxClamped(Number(e.target.value) || 0)}
            className="h-10 w-full min-w-16 rounded-lg border border-line text-center text-sm font-bold text-ink sm:h-9 sm:w-20"
          />
          <button
            type="button"
            aria-label="Increase maximum capacity"
            onClick={() => setMaxClamped(max + 5)}
            className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-line text-lg text-ink-soft hover:border-brand hover:text-ink sm:h-9 sm:w-9 sm:text-base"
          >
            +
          </button>
        </div>
      </div>

      {/* The band ribbon — a picture of the three ranges at their current sizes. */}
      <div className="mt-5">
        <div className="flex h-4 overflow-hidden rounded-full">
          <span style={{ width: pct(light), backgroundColor: CATEGORY_COLORS.light }} />
          <span
            style={{ width: pct(moderate - light), backgroundColor: CATEGORY_COLORS.moderate }}
          />
          <span
            style={{ width: pct(max - moderate), backgroundColor: CATEGORY_COLORS.heavy }}
          />
        </div>
        <div className="mt-1 flex justify-between gap-2 text-xs text-ink-faint">
          <span>1</span>
          {/* The midpoint is a nicety; it goes first when the row gets tight. */}
          <span className="hidden sm:inline">{Math.round(max / 2)}</span>
          <span>{max}</span>
        </div>
      </div>

      <BandSlider
        label="Light Workload Capacity"
        badge={`1-${light}`}
        color={CATEGORY_COLORS.light}
        value={light}
        min={1}
        max={Math.max(1, moderate - 1)}
        onChange={setLightClamped}
      />
      <BandSlider
        label="Moderate Workload Capacity"
        badge={`${light + 1}-${moderate}`}
        color={CATEGORY_COLORS.moderate}
        value={moderate}
        min={light + 1}
        max={Math.max(light + 1, max - 1)}
        onChange={setModerateClamped}
      />

      <div className="mt-5 flex items-start gap-3 rounded-xl border border-line bg-canvas px-3 py-3 sm:px-4">
        <span
          className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-ink text-[11px] font-bold text-white"
          aria-hidden
        >
          !
        </span>
        <p className="text-sm text-ink-soft">
          With these thresholds:{" "}
          <span className="font-bold text-ink">
            {preview.light} light, {preview.moderate} moderate and {preview.heavy} heavy
          </span>{" "}
          {bars.length === 1 ? "member" : "members"}.
          <br />
          <span className="font-bold text-ink">Heavy work starts above {moderate}.</span>
        </p>
      </div>

      </>
      )}
    </Modal>
  );
}

/**
 * The confirmation step: what is changing, and who it moves.
 *
 * These bands are platform-wide and take effect immediately — on this chart, on
 * every board, and in the task modal's assignment indicator — so the step spells
 * out the before/after and names the people whose workload label changes rather
 * than asking a bare "are you sure?".
 */
function ConfirmStep({
  from,
  to,
  movers,
  memberCount,
  error,
}: {
  from: WorkloadThresholds;
  to: WorkloadThresholds;
  movers: { name: string; from: string; to: string }[];
  memberCount: number;
  error: boolean;
}) {
  const rows: { label: string; before: number; after: number }[] = [
    { label: "Maximum capacity", before: from.max, after: to.max },
    { label: "Light ends at", before: from.light, after: to.light },
    { label: "Moderate ends at", before: from.moderate, after: to.moderate },
  ];

  return (
    <div>
      <p className="-mt-1 text-sm text-ink-soft">
        These bands apply to <span className="font-bold text-ink">everyone</span>,
        immediately — here, on every board, and when assigning a task.
      </p>

      <div className="mt-4 overflow-hidden rounded-xl border border-line">
        <div className="overflow-x-auto">
        <table className="w-full min-w-[300px] border-collapse text-sm">
          <thead>
            <tr className="border-b border-line bg-[#f9fafb]">
              <th className="px-4 py-2 text-left text-xs font-bold uppercase tracking-wide text-ink-soft">
                Setting
              </th>
              <th className="px-4 py-2 text-right text-xs font-bold uppercase tracking-wide text-ink-soft">
                Now
              </th>
              <th className="px-4 py-2 text-right text-xs font-bold uppercase tracking-wide text-ink-soft">
                After
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.label} className="border-b border-line last:border-b-0">
                <td className="px-4 py-2 text-ink">{r.label}</td>
                <td className="px-4 py-2 text-right text-ink-soft">{r.before}</td>
                <td
                  className={`px-4 py-2 text-right font-bold ${
                    r.before === r.after ? "text-ink-soft" : "text-ink"
                  }`}
                >
                  {r.after}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        </div>
      </div>

      <p className="mt-5 text-sm font-bold text-ink">
        {movers.length === 0
          ? "No one changes workload band."
          : `${movers.length} of ${memberCount} ${
              memberCount === 1 ? "member" : "members"
            } change workload band:`}
      </p>

      {movers.length > 0 && (
        <ul className="mt-2 max-h-40 space-y-1.5 overflow-y-auto pr-1">
          {movers.map((m) => (
            <li key={m.name} className="flex items-center gap-2 text-sm leading-6">
              <span className="min-w-0 flex-1 truncate text-ink">{m.name}</span>
              <span className="shrink-0 capitalize text-ink-faint">{m.from}</span>
              <span className="shrink-0 text-ink-faint" aria-hidden>
                →
              </span>
              <span
                className="shrink-0 font-bold capitalize"
                style={{ color: CATEGORY_COLORS[m.to as keyof typeof CATEGORY_COLORS] }}
              >
                {m.to}
              </span>
            </li>
          ))}
        </ul>
      )}

      {error && (
        <p className="mt-4 text-sm text-danger">
          Could not apply the thresholds. Go back and check the values.
        </p>
      )}
    </div>
  );
}

function BandSlider({
  label,
  badge,
  color,
  value,
  min,
  max,
  onChange,
}: {
  label: string;
  badge: string;
  color: string;
  value: number;
  min: number;
  max: number;
  onChange: (n: number) => void;
}) {
  return (
    <div className="mt-5">
      <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
        <p className="text-sm font-bold text-ink">{label}</p>
        <span
          className="rounded-full px-2.5 py-0.5 text-xs font-bold"
          style={{ backgroundColor: `${color}1a`, color }}
        >
          {badge}
        </span>
      </div>
      <input
        type="range"
        aria-label={label}
        value={value}
        min={min}
        max={max}
        onChange={(e) => onChange(Number(e.target.value))}
        className="mt-2 h-6 w-full cursor-pointer touch-pan-y"
        style={{ accentColor: color }}
      />
    </div>
  );
}
