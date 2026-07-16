"use client";

import { useRef, useState } from "react";
import { Card } from "@/core/ui";
import { isOverdue } from "../../overdue";
import { dayKey, type WorkItem } from "./shared";

const WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

/**
 * A task chip inside a calendar day. A green ring marks a done task, a red ring
 * marks an overdue one (mirroring the overdue indicator elsewhere).
 */
function TaskChip({
  item,
  onOpen,
}: {
  item: WorkItem;
  onOpen: (taskId: string) => void;
}): React.ReactElement {
  const done = item.statusKey === "done";
  const overdue = !done && isOverdue(item.deadline, item.statusKey);
  return (
    <button
      type="button"
      onClick={() => onOpen(item.id)}
      className={`block w-full truncate rounded px-1.5 py-0.5 text-left text-[11px] font-semibold text-white hover:opacity-80 ${
        done
          ? "ring-2 ring-success ring-offset-1"
          : overdue
            ? "ring-2 ring-danger ring-offset-1"
            : ""
      }`}
      style={{ backgroundColor: item.projectColor ?? "#6366f1" }}
      title={
        done
          ? `${item.title} — done`
          : overdue
            ? `${item.title} — overdue`
            : item.title
      }
    >
      {done && <span aria-hidden>✓ </span>}
      {overdue && <span aria-hidden>⚠ </span>}
      {item.title}
    </button>
  );
}

/**
 * A single task row in the mobile agenda list — full-width and readable, with a
 * project-color dot and done/overdue markers (the month grid is too narrow to
 * show titles on phones, so small screens get this list instead).
 */
function AgendaItem({
  item,
  onOpen,
}: {
  item: WorkItem;
  onOpen: (taskId: string) => void;
}): React.ReactElement {
  const done = item.statusKey === "done";
  const overdue = !done && isOverdue(item.deadline, item.statusKey);
  return (
    <button
      type="button"
      onClick={() => onOpen(item.id)}
      className="flex w-full items-center gap-2 rounded-lg border border-line bg-surface px-3 py-2 text-left transition-colors hover:bg-black/[0.02]"
    >
      <span
        className="h-2.5 w-2.5 shrink-0 rounded-full"
        style={{ backgroundColor: item.projectColor ?? "#6366f1" }}
      />
      <span className="min-w-0 flex-1 truncate text-sm font-medium text-ink">
        {item.title}
      </span>
      {done && (
        <span className="shrink-0 text-xs font-bold text-success" aria-label="done">
          ✓
        </span>
      )}
      {overdue && (
        <span className="shrink-0 text-xs font-bold text-danger" aria-label="overdue">
          ⚠
        </span>
      )}
    </button>
  );
}

/**
 * One calendar day. Shows up to 3 task chips; when the day holds 4+ tasks a
 * hover popover reveals the full list (each still clickable). `isToday`
 * highlights the current date.
 */
function DayCell({
  day,
  tasks,
  isToday,
  onOpen,
}: {
  day: number;
  tasks: WorkItem[];
  isToday: boolean;
  onOpen: (taskId: string) => void;
}): React.ReactElement {
  const [open, setOpen] = useState(false);
  const [pos, setPos] = useState<{ top: number; left: number; above: boolean } | null>(
    null,
  );
  const cellRef = useRef<HTMLDivElement>(null);
  const closeTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const hasMore = tasks.length >= 4;

  const cancelClose = (): void => {
    if (closeTimer.current) {
      clearTimeout(closeTimer.current);
      closeTimer.current = null;
    }
  };
  const scheduleClose = (): void => {
    cancelClose();
    closeTimer.current = setTimeout(() => setOpen(false), 120);
  };
  const openPopover = (): void => {
    if (!hasMore) return;
    cancelClose();
    const rect = cellRef.current?.getBoundingClientRect();
    if (rect) {
      // Flip above the cell if there isn't room below.
      const above = rect.bottom + 260 > window.innerHeight;
      setPos({
        top: above ? rect.top : rect.bottom,
        left: Math.max(8, Math.min(rect.left, window.innerWidth - 240)),
        above,
      });
    }
    setOpen(true);
  };

  return (
    <div
      ref={cellRef}
      className={`relative min-h-16 p-1 sm:min-h-24 sm:p-1.5 ${
        isToday ? "bg-brand/5" : "bg-surface"
      }`}
      onMouseEnter={openPopover}
      onMouseLeave={scheduleClose}
    >
      <span
        className={`inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-xs font-bold ${
          isToday ? "bg-brand text-ink" : "text-ink-soft"
        }`}
      >
        {day}
      </span>
      <div className="mt-1 space-y-1 m-1">
        {tasks.slice(0, 3).map((t) => (
          <TaskChip key={t.key} item={t} onOpen={onOpen} />
        ))}
        {hasMore && (
          <span className="block px-1 text-[11px] font-medium text-ink-faint">
            +{tasks.length - 3} more
          </span>
        )}
      </div>

      {open && pos && (
        <div
          className="fixed z-40 w-60 rounded-xl border border-line bg-surface p-2 shadow-xl"
          style={{
            left: pos.left,
            top: pos.above ? undefined : pos.top + 4,
            bottom: pos.above ? window.innerHeight - pos.top + 4 : undefined,
          }}
          onMouseEnter={cancelClose}
          onMouseLeave={scheduleClose}
        >
          <p className="mb-1.5 px-1 text-[10px] font-bold uppercase tracking-wide text-ink-faint">
            {tasks.length} tasks
          </p>
          <div className="max-h-56 space-y-1 overflow-y-auto p-1.5">
            {tasks.map((t) => (
              <TaskChip key={t.key} item={t} onOpen={onOpen} />
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

export function CalendarView({
  items,
  onOpen,
}: {
  items: WorkItem[];
  onOpen: (taskId: string) => void;
}): React.ReactElement {
  const [cursor, setCursor] = useState(() => new Date());
  const year = cursor.getFullYear();
  const month = cursor.getMonth();

  const first = new Date(year, month, 1);
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const leading = first.getDay();

  const byDay = new Map<string, WorkItem[]>();
  for (const i of items) {
    const k = dayKey(i.deadline);
    if (!k) continue;
    if (!byDay.has(k)) byDay.set(k, []);
    byDay.get(k)!.push(i);
  }

  const cells: (number | null)[] = [
    ...Array.from({ length: leading }, () => null),
    ...Array.from({ length: daysInMonth }, (_, i) => i + 1),
  ];
  const pad = (n: number): string => String(n).padStart(2, "0");
  const monthLabel = cursor.toLocaleDateString("en-US", {
    month: "long",
    year: "numeric",
  });

  // Key for the current date, used to highlight "today".
  const now = new Date();
  const todayKey = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(
    now.getDate(),
  )}`;

  // Mobile agenda: only the days of this month that actually hold tasks, in
  // date order — a readable alternative to the too-narrow grid on phones.
  const agendaDays = Array.from({ length: daysInMonth }, (_, i) => i + 1)
    .map((day) => {
      const k = `${year}-${pad(month + 1)}-${pad(day)}`;
      return { day, k, tasks: byDay.get(k) ?? [] };
    })
    .filter((d) => d.tasks.length > 0);

  return (
    <Card className="overflow-hidden p-4 sm:p-5">
      <div className="mb-4 flex items-center justify-between">
        <h3 className="text-lg font-bold text-ink">{monthLabel}</h3>
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => setCursor(new Date())}
            className="rounded-lg border border-line px-2.5 py-1 text-xs font-bold text-ink-soft hover:bg-black/5"
          >
            Today
          </button>
          <button
            type="button"
            onClick={() => setCursor(new Date(year, month - 1, 1))}
            className="flex h-8 w-8 items-center justify-center rounded-lg border border-line text-ink-soft hover:bg-black/5"
            aria-label="Previous month"
          >
            ‹
          </button>
          <button
            type="button"
            onClick={() => setCursor(new Date(year, month + 1, 1))}
            className="flex h-8 w-8 items-center justify-center rounded-lg border border-line text-ink-soft hover:bg-black/5"
            aria-label="Next month"
          >
            ›
          </button>
        </div>
      </div>

      {/* Mobile: agenda list (the 7-column grid is unreadable on phones) */}
      <div className="space-y-4 sm:hidden">
        {agendaDays.length === 0 ? (
          <p className="py-10 text-center text-sm text-ink-soft">
            No tasks this month.
          </p>
        ) : (
          agendaDays.map(({ day, k, tasks }) => {
            const isToday = k === todayKey;
            const weekday = new Date(year, month, day).toLocaleDateString(
              "en-US",
              { weekday: "short" },
            );
            return (
              <div key={k} className="flex gap-3">
                <div className="flex w-10 shrink-0 flex-col items-center pt-0.5">
                  <span
                    className={`flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold ${
                      isToday ? "bg-brand text-ink" : "text-ink"
                    }`}
                  >
                    {day}
                  </span>
                  <span className="mt-0.5 text-[10px] font-bold uppercase text-ink-faint">
                    {weekday}
                  </span>
                </div>
                <div className="min-w-0 flex-1 space-y-1.5">
                  {tasks.map((t) => (
                    <AgendaItem key={t.key} item={t} onOpen={onOpen} />
                  ))}
                </div>
              </div>
            );
          })
        )}
      </div>

      {/* Desktop/tablet: month grid */}
      <div className="hidden grid-cols-7 gap-px overflow-hidden rounded-lg border border-line bg-line text-sm sm:grid">
        {WEEKDAYS.map((d) => (
          <div
            key={d}
            className="bg-surface px-1 py-2 text-center text-[10px] font-bold uppercase text-ink-faint sm:px-2 sm:text-xs"
          >
            {d}
          </div>
        ))}
        {cells.map((day, i) => {
          if (!day) {
            return (
              <div key={i} className="min-h-16 bg-surface sm:min-h-24" />
            );
          }
          const k = `${year}-${pad(month + 1)}-${pad(day)}`;
          return (
            <DayCell
              key={i}
              day={day}
              tasks={byDay.get(k) ?? []}
              isToday={k === todayKey}
              onOpen={onOpen}
            />
          );
        })}
      </div>
    </Card>
  );
}
