"use client";

import { useState } from "react";
import { Button, Modal } from "@/core/ui";
import { useUpdateWorkloadCapacity } from "../../hooks";
import type { WorkloadBar, WorkloadThresholds } from "../../types";
import { CATEGORY_COLORS } from "./shared";

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

  // Re-seed from the server whenever the modal is (re)opened, so a cancelled
  // edit never lingers. Done during render, React's recommended pattern.
  const [wasOpen, setWasOpen] = useState(open);
  if (open !== wasOpen) {
    setWasOpen(open);
    if (open) {
      setMax(thresholds.max);
      setLight(thresholds.light);
      setModerate(thresholds.moderate);
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
        <div className="flex w-full items-center justify-between gap-3">
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
            <Button variant="outline" onClick={onClose} disabled={update.isPending}>
              Cancel
            </Button>
            <Button onClick={apply} disabled={update.isPending}>
              {update.isPending ? "Applying…" : "Apply Thresholds"}
            </Button>
          </div>
        </div>
      }
    >
      <p className="-mt-1 mb-4 text-sm text-ink-soft">
        Sets when a member&apos;s total effort counts as Light, Moderate or Heavy —
        everywhere in Sprintly.
      </p>

      {/* Maximum capacity — the top of the range the bands are drawn against. */}
      <div className="flex items-center justify-between gap-4 rounded-xl border border-line px-4 py-3">
        <div>
          <p className="text-sm font-bold text-ink">Maximum Capacity</p>
          <p className="text-xs text-ink-soft">Top of the effort range — bands rescale with it</p>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <button
            type="button"
            aria-label="Decrease maximum capacity"
            onClick={() => setMaxClamped(max - 5)}
            className="flex h-9 w-9 items-center justify-center rounded-lg border border-line text-ink-soft hover:border-brand hover:text-ink"
          >
            −
          </button>
          <input
            type="number"
            value={max}
            aria-label="Maximum capacity"
            onChange={(e) => setMaxClamped(Number(e.target.value) || 0)}
            className="h-9 w-20 rounded-lg border border-line text-center text-sm font-bold text-ink"
          />
          <button
            type="button"
            aria-label="Increase maximum capacity"
            onClick={() => setMaxClamped(max + 5)}
            className="flex h-9 w-9 items-center justify-center rounded-lg border border-line text-ink-soft hover:border-brand hover:text-ink"
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
        <div className="mt-1 flex justify-between text-xs text-ink-faint">
          <span>1</span>
          <span>{Math.round(max / 2)}</span>
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

      <div className="mt-5 flex items-start gap-3 rounded-xl border border-line bg-canvas px-4 py-3">
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

      {update.isError && (
        <p className="mt-3 text-sm text-danger">
          Could not apply the thresholds. Check the values and try again.
        </p>
      )}
    </Modal>
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
      <div className="flex items-center justify-between gap-3">
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
        className="mt-2 w-full"
        style={{ accentColor: color }}
      />
    </div>
  );
}
