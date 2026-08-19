"use client";

import { useCallback, useEffect, useId, useMemo, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { FolderIcon, PriorityBadge, SearchIcon, XIcon } from "@/core/ui";
import { useDebouncedValue } from "@/core/use-debounced-value";
import { useProjectSearch } from "@/modules/projects/hooks/use-search";
import { SEARCH_MIN_LENGTH } from "@/modules/projects/types/search";
import type {
  SearchProjectHit,
  SearchSubtaskHit,
  SearchTaskHit,
} from "@/modules/projects/types/search";
import { useOpenTask } from "@/modules/projects/hooks/use-task-modal";

/** Keystroke → request delay. Long enough to skip mid-word, short enough to feel live. */
const DEBOUNCE_MS = 250;
/** Rows shown per section. The API caps its own results at this too. */
const PER_SECTION = 5;

/**
 * One selectable row, flattened across sections so the arrow keys can walk the
 * whole menu as a single list regardless of how it's grouped visually.
 */
type Hit =
  | { kind: "project"; item: SearchProjectHit }
  | { kind: "task"; item: SearchTaskHit }
  | { kind: "subtask"; item: SearchSubtaskHit };

function hitKey(hit: Hit): string {
  return `${hit.kind}:${hit.item.id}`;
}

/**
 * Topbar global search — jump to a project, task or sub-task from anywhere in
 * the module.
 *
 * The input stays uncontrolled-feeling (bound to raw state, never to the
 * in-flight query) while the request is driven by a debounced copy of the term,
 * so typing never waits on the network. Selecting a project routes to it;
 * selecting a task — or a sub-task, via its parent — opens the global task
 * modal in place via `?task=`.
 */
export function GlobalSearch() {
  const [term, setTerm] = useState("");
  const [open, setOpen] = useState(false);
  const [activeIndex, setActiveIndex] = useState(0);

  const containerRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const listboxId = useId();

  const router = useRouter();
  const openTask = useOpenTask();

  const debouncedTerm = useDebouncedValue(term, DEBOUNCE_MS);
  const { data, isFetching, isError } = useProjectSearch(
    debouncedTerm,
    PER_SECTION,
  );

  const trimmed = term.trim();
  const isTermLongEnough = trimmed.length >= SEARCH_MIN_LENGTH;
  // True only while the *current* term has no results yet — with
  // `keepPreviousData` the previous term's rows stay visible meanwhile.
  const isSettling = trimmed !== debouncedTerm.trim() || isFetching;

  const projects = useMemo(() => data?.projects ?? [], [data]);
  const tasks = useMemo(() => data?.tasks ?? [], [data]);
  // A sub-task has no page of its own — it is reached through its parent task,
  // so one with no parent in the payload isn't openable and is dropped.
  const subtasks = useMemo(
    () => (data?.subtasks ?? []).filter((s) => s.task !== null),
    [data],
  );

  // Flattened, in visual order — this is what the keyboard walks.
  const hits = useMemo<Hit[]>(
    () => [
      ...projects.map((item): Hit => ({ kind: "project", item })),
      ...tasks.map((item): Hit => ({ kind: "task", item })),
      ...subtasks.map((item): Hit => ({ kind: "subtask", item })),
    ],
    [projects, tasks, subtasks],
  );

  // A new term invalidates the old highlight position. Adjusted during render
  // (React's recommended pattern) rather than in an effect, so the menu never
  // paints one frame with the previous row highlighted.
  const [highlightedTerm, setHighlightedTerm] = useState(debouncedTerm);
  if (debouncedTerm !== highlightedTerm) {
    setHighlightedTerm(debouncedTerm);
    setActiveIndex(0);
  }

  // Results can shrink under the cursor (a slower request settling with fewer
  // rows), so never trust the raw index when indexing into `hits`.
  const cursor = hits.length > 0 ? Math.min(activeIndex, hits.length - 1) : 0;

  const close = useCallback(() => {
    setOpen(false);
    setActiveIndex(0);
  }, []);

  const reset = useCallback(() => {
    setTerm("");
    close();
  }, [close]);

  const select = useCallback(
    (hit: Hit) => {
      reset();
      if (hit.kind === "project") {
        router.push(`/sprintly/projects/${hit.item.id}`);
        return;
      }
      // The task modal is mounted in the /sprintly layout, and this component
      // only renders there — so opening in place always works. A sub-task opens
      // the parent task's modal, which is where sub-tasks are listed and edited.
      const taskId =
        hit.kind === "task" ? hit.item.id : (hit.item.task?.id ?? null);
      if (taskId) openTask(taskId);
    },
    [openTask, reset, router],
  );

  // Close on outside click / focus leaving the widget.
  useEffect(() => {
    if (!open) return;
    const onPointerDown = (e: MouseEvent) => {
      if (!containerRef.current?.contains(e.target as Node)) close();
    };
    document.addEventListener("mousedown", onPointerDown);
    return () => document.removeEventListener("mousedown", onPointerDown);
  }, [open, close]);

  // ⌘K / Ctrl+K focuses the input from anywhere in the module.
  useEffect(() => {
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key.toLowerCase() !== "k" || !(e.metaKey || e.ctrlKey)) return;
      e.preventDefault();
      inputRef.current?.focus();
      inputRef.current?.select();
      setOpen(true);
    };
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, []);

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Escape") {
      // First Escape dismisses the menu, a second one clears the box.
      if (open) close();
      else setTerm("");
      return;
    }

    if (e.key === "ArrowDown" || e.key === "ArrowUp") {
      if (!hits.length) return;
      e.preventDefault();
      setOpen(true);
      setActiveIndex((i) => {
        const next = e.key === "ArrowDown" ? i + 1 : i - 1;
        // Wrap, so the list has no dead ends.
        return (next + hits.length) % hits.length;
      });
      return;
    }

    if (e.key === "Enter") {
      const hit = hits[cursor];
      if (hit) {
        e.preventDefault();
        select(hit);
      }
    }
  };

  const showMenu = open && trimmed.length > 0;
  const hasHits = hits.length > 0;

  return (
    <div ref={containerRef} className="relative w-full max-w-md">
      <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-faint" />
      <input
        ref={inputRef}
        type="text"
        role="combobox"
        aria-expanded={showMenu}
        aria-controls={listboxId}
        aria-autocomplete="list"
        aria-activedescendant={
          showMenu && hits[cursor]
            ? `${listboxId}-${hitKey(hits[cursor])}`
            : undefined
        }
        value={term}
        onChange={(e) => {
          setTerm(e.target.value);
          setOpen(true);
        }}
        onFocus={() => setOpen(true)}
        onKeyDown={handleKeyDown}
        placeholder="Search projects, tasks and sub-tasks…"
        aria-label="Search projects, tasks and sub-tasks"
        className="h-9 w-full rounded-lg border border-line bg-surface pl-9 pr-8 text-sm text-ink placeholder:text-ink-faint focus:border-brand focus:outline-none"
      />
      {term && (
        <button
          type="button"
          onClick={() => {
            reset();
            inputRef.current?.focus();
          }}
          aria-label="Clear search"
          className="absolute right-2 top-1/2 flex h-5 w-5 -translate-y-1/2 items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-black/5 hover:text-ink"
        >
          <XIcon className="h-3.5 w-3.5" />
        </button>
      )}

      {showMenu && (
        <div
          id={listboxId}
          role="listbox"
          aria-label="Search results"
          className="absolute left-0 top-[calc(100%+8px)] z-20 w-full overflow-hidden rounded-2xl border border-line bg-surface shadow-xl"
        >
          <div className="max-h-[380px] overflow-y-auto py-1">
            {!isTermLongEnough ? (
              <p className="px-4 py-6 text-center text-sm text-ink-soft">
                Keep typing — at least {SEARCH_MIN_LENGTH} characters.
              </p>
            ) : isError ? (
              <p className="px-4 py-6 text-center text-sm text-danger">
                Search is unavailable right now. Try again in a moment.
              </p>
            ) : !hasHits ? (
              <p className="px-4 py-6 text-center text-sm text-ink-soft">
                {isSettling ? "Searching…" : `No matches for “${trimmed}”.`}
              </p>
            ) : (
              <>
                {projects.length > 0 && (
                  <Section label="Projects">
                    {projects.map((project, i) => (
                      <ProjectRow
                        key={project.id}
                        id={`${listboxId}-project:${project.id}`}
                        project={project}
                        active={cursor === i}
                        onHover={() => setActiveIndex(i)}
                        onSelect={() => select({ kind: "project", item: project })}
                      />
                    ))}
                  </Section>
                )}

                {tasks.length > 0 && (
                  <Section label="Tasks">
                    {tasks.map((task, i) => {
                      const index = projects.length + i;
                      return (
                        <TaskRow
                          key={task.id}
                          id={`${listboxId}-task:${task.id}`}
                          task={task}
                          active={cursor === index}
                          onHover={() => setActiveIndex(index)}
                          onSelect={() => select({ kind: "task", item: task })}
                        />
                      );
                    })}
                  </Section>
                )}

                {subtasks.length > 0 && (
                  <Section label="Sub-tasks">
                    {subtasks.map((subtask, i) => {
                      const index = projects.length + tasks.length + i;
                      return (
                        <SubtaskRow
                          key={subtask.id}
                          id={`${listboxId}-subtask:${subtask.id}`}
                          subtask={subtask}
                          active={cursor === index}
                          onHover={() => setActiveIndex(index)}
                          onSelect={() =>
                            select({ kind: "subtask", item: subtask })
                          }
                        />
                      );
                    })}
                  </Section>
                )}
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

function Section({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="border-b border-line py-1 last:border-0">
      <p className="px-4 pb-1 pt-2 text-xs font-bold uppercase tracking-wide text-ink-soft">
        {label}
      </p>
      {children}
    </div>
  );
}

/** Shared row chrome — keeps the two result kinds visually identical. */
function Row({
  id,
  active,
  onHover,
  onSelect,
  children,
}: {
  id: string;
  active: boolean;
  onHover: () => void;
  onSelect: () => void;
  children: React.ReactNode;
}) {
  return (
    <button
      id={id}
      role="option"
      aria-selected={active}
      type="button"
      // `onMouseDown` fires before the input's blur, so the click always lands.
      onMouseDown={(e) => {
        e.preventDefault();
        onSelect();
      }}
      onMouseEnter={onHover}
      className={`flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors ${
        active ? "bg-brand-soft" : "hover:bg-black/[0.03]"
      }`}
    >
      {children}
    </button>
  );
}

function ProjectRow({
  id,
  project,
  active,
  onHover,
  onSelect,
}: {
  id: string;
  project: SearchProjectHit;
  active: boolean;
  onHover: () => void;
  onSelect: () => void;
}) {
  const context = [
    project.workspace?.name,
    `${project.tasks_count} ${project.tasks_count === 1 ? "task" : "tasks"}`,
  ]
    .filter(Boolean)
    .join(" · ");

  return (
    <Row id={id} active={active} onHover={onHover} onSelect={onSelect}>
      <span
        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
        style={{ backgroundColor: `${project.color ?? "#6366f1"}1f` }}
        aria-hidden
      >
        <FolderIcon
          className="h-4 w-4"
          style={{ color: project.color ?? "#6366f1" }}
        />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-bold text-ink">
          {project.name}
        </span>
        <span className="block truncate text-xs text-ink-soft">{context}</span>
      </span>
      {project.status === "done" && (
        <span className="shrink-0 text-xs font-bold text-ink-faint">Done</span>
      )}
    </Row>
  );
}

function TaskRow({
  id,
  task,
  active,
  onHover,
  onSelect,
}: {
  id: string;
  task: SearchTaskHit;
  active: boolean;
  onHover: () => void;
  onSelect: () => void;
}) {
  const context = [task.project?.name, task.status?.name, task.assignee?.name]
    .filter(Boolean)
    .join(" · ");
  return (
    <Row id={id} active={active} onHover={onHover} onSelect={onSelect}>
      <span
        className="mt-1 h-2 w-2 shrink-0 self-start rounded-full"
        style={{ backgroundColor: task.status?.color ?? "#94a3b8" }}
        aria-hidden
      />
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-bold text-ink">
          {task.title}
        </span>
        <span className="block truncate text-xs text-ink-soft">{context}</span>
      </span>
      {task.priority && (
        <span className="shrink-0">
          <PriorityBadge priority={task.priority} />
        </span>
      )}
    </Row>
  );
}

function SubtaskRow({
  id,
  subtask,
  active,
  onHover,
  onSelect,
}: {
  id: string;
  subtask: SearchSubtaskHit;
  active: boolean;
  onHover: () => void;
  onSelect: () => void;
}) {
  // Parent task first — it's both the strongest identifier for a sub-task with
  // a generic name and where clicking this row actually lands you.
  const context = [
    subtask.task?.title,
    subtask.project?.name,
    subtask.status?.name,
  ]
    .filter(Boolean)
    .join(" · ");

  return (
    <Row id={id} active={active} onHover={onHover} onSelect={onSelect}>
      {/* Elbow + status dot — reads as "nested under something". */}
      <span
        className="mt-0.5 flex shrink-0 items-end gap-1 self-start"
        aria-hidden
      >
        <span className="h-3 w-2 rounded-bl-[3px] border-b border-l border-line" />
        <span
          className="-mb-px h-2 w-2 rounded-full"
          style={{ backgroundColor: subtask.status?.color ?? "#94a3b8" }}
        />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-bold text-ink">
          {subtask.name}
        </span>
        <span className="block truncate text-xs text-ink-soft">{context}</span>
      </span>
      {subtask.priority && (
        <span className="shrink-0">
          <PriorityBadge priority={subtask.priority} />
        </span>
      )}
    </Row>
  );
}
